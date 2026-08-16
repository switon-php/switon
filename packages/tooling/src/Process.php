<?php

declare(strict_types=1);

namespace Switon\Tooling;

use Switon\Ai\AgentInterface;
use Switon\Ai\Event\ToolResultDelta;
use Switon\Ai\ToolCall;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ClockInterface;
use Switon\Core\Path;
use Switon\Core\ProcessTree;
use Switon\Eventing\EventDispatcherInterface;

use function array_slice;
use function count;
use function explode;
use function fclose;
use function feof;
use function fopen;
use function fread;
use function fseek;
use function ftell;
use function getenv;
use function is_dir;
use function is_file;
use function is_int;
use function is_resource;
use function is_string;
use function preg_match;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function rtrim;
use function str_contains;
use function str_replace;
use function stream_get_meta_data;
use function stream_set_blocking;
use function strlen;
use function strpos;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const PATH_SEPARATOR;

use const PHP_OS_FAMILY;

/**
 * One process per instance for Linux, macOS, or Windows Git Bash. Do not reuse it.
 *
 * Guidance: {@see ProcessInterface::start()} opens it; {@see ProcessInterface::output()} or {@see ProcessInterface::lines()} collects once and closes it.
 */
class Process implements ProcessInterface
{
    protected const float ABORT_POLL_SECONDS = 0.1;

    protected const int CANCELLED_EXIT_CODE = 130;

    #[Autowired] protected EventDispatcherInterface $eventDispatcher;
    #[Autowired] protected ClockInterface $clock;

    /** Process wall-clock cap in seconds. Default is 7 days. */
    #[Autowired] protected int $timeout = 7 * 86400;
    /** In-memory stdout/stderr collection cap in KiB. Further body is cut; {@see Spiller::ceilingExceeded()} is appended once. */
    #[Autowired] protected int $ceiling = 32 * 1024;
    /** Inline / spill-start threshold in KiB. */
    #[Autowired] protected int $budget = 32;
    /** Preview edge length in bytes. */
    #[Autowired] protected int $keep = 1024;
    /** When false, return ceiling-capped buffers and leave spill to the caller. */
    #[Autowired] protected bool $spill = true;

    #[Autowired] protected SpillerInterface $spiller;
    #[Autowired] protected AgentInterface $agent;
    #[Autowired] protected ToolCall $call;

    /** @var resource */
    protected $process;

    /** @var array<int, resource> */
    protected array $pipes = [];

    /**
     * Windows stdout/stderr files. A non-blocking `fread()` on a `proc_open` pipe still blocks.
     *
     * @var list<string>
     */
    protected array $captureFiles = [];

    protected float $startedAt = 0.0;

    protected float $deadline = 0.0;

    /**
     * @param list<string> $command
     * @param string $cwd Absolute working directory.
     * @param int $timeout Wall-clock cap in seconds.
     */
    public function start(array $command, string $cwd, int $timeout): static
    {
        $stdoutFile = null;
        $stderrFile = null;
        if (PHP_OS_FAMILY === 'Windows') {
            $stdoutFile = tempnam(sys_get_temp_dir(), 'switon-proc-out-');
            $stderrFile = tempnam(sys_get_temp_dir(), 'switon-proc-err-');
            if ($stdoutFile === false || $stderrFile === false) {
                if (is_string($stdoutFile)) {
                    @unlink($stdoutFile);
                }
                if (is_string($stderrFile)) {
                    @unlink($stderrFile);
                }
                Exception::raise('Failed to create process capture files.');
            }
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['file', $stdoutFile, 'w'],
                2 => ['file', $stderrFile, 'w'],
            ];
        } else {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
        }

        $cwd = $this->directory($cwd);
        /** @var array<int, resource> $pipes */
        $pipes = [];
        try {
            $this->process = $this->open($command, $descriptors, $pipes, $cwd);
        } catch (Exception $e) {
            if (is_string($stdoutFile)) {
                @unlink($stdoutFile);
            }
            if (is_string($stderrFile)) {
                @unlink($stderrFile);
            }
            throw $e;
        }

        fclose($pipes[0]);
        if (is_string($stdoutFile) && is_string($stderrFile)) {
            $stdout = fopen($stdoutFile, 'rb');
            $stderr = fopen($stderrFile, 'rb');
            if ($stdout === false || $stderr === false) {
                if (is_resource($stdout)) {
                    fclose($stdout);
                }
                if (is_resource($stderr)) {
                    fclose($stderr);
                }
                if (is_resource($this->process)) {
                    @proc_terminate($this->process);
                    @proc_close($this->process);
                }
                @unlink($stdoutFile);
                @unlink($stderrFile);
                Exception::raise('Failed to open process capture files.');
            }
            $pipes[1] = $stdout;
            $pipes[2] = $stderr;
            $this->captureFiles = [$stdoutFile, $stderrFile];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $this->pipes = $pipes;
        $this->startedAt = $this->clock->microtime();
        $this->deadline = $this->startedAt + max(1, $timeout);

        return $this;
    }

    /**
     * Read stdout and stderr until the process ends, the deadline hits, `$pattern` matches, or `$halt` returns true.
     *
     * `$delta` is dispatched per chunk with `chunk` and `channel` filled here.
     * Buffers are capped at the configured ceiling, then spilled for this call.
     * `$halt` sees the stdout collected so far after each chunk. True stops the process (`halted`).
     *
     * @param null|callable(string): bool $halt
     *
     * @return array{exit: int, stdout: string, stderr: string, timeout?: bool, cancelled?: bool, matched?: bool, halted?: bool, elapsed?: float}
     */
    public function output(
        ToolResultDelta $delta,
        ?string         $pattern = null,
        ?callable       $halt = null,
    ): array {
        $pattern = $pattern !== null && $pattern !== '' ? $pattern : null;
        if ($pattern !== null) {
            $this->assertValidPattern($pattern);
        }

        $stdout = ['buffer' => '', 'capped' => false];
        $stderr = ['buffer' => '', 'capped' => false];
        $timedOut = false;
        $cancelled = false;
        $matched = false;
        $halted = false;
        $exitCode = null;
        $stdoutWindow = '';
        $stderrWindow = '';

        while (true) {
            $stdoutWindow = $this->readCollect($this->pipes[1], $stdout, $delta, 'stdout', $pattern, $matched, $stdoutWindow, $halt, $halted);
            $stderrWindow = $this->readCollect($this->pipes[2], $stderr, $delta, 'stderr', $pattern, $matched, $stderrWindow);

            if ($halted || ($pattern !== null && $matched)) {
                $this->terminateTree();
                if (!$halted) {
                    $this->readCollect($this->pipes[1], $stdout, $delta, 'stdout', null, $matched);
                }
                $this->readCollect($this->pipes[2], $stderr, $delta, 'stderr', null, $matched);
                break;
            }

            if ($this->stopped()) {
                $exitCode = $this->abort($delta, $stdout, $stderr);
                $cancelled = true;
                break;
            }

            $status = proc_get_status($this->process);
            if (!$status['running']) {
                $exitCode = $this->exitCodeFromStatus($status) ?? $exitCode;
                $this->readCollect($this->pipes[1], $stdout, $delta, 'stdout', null, $matched);
                $this->readCollect($this->pipes[2], $stderr, $delta, 'stderr', null, $matched);
                break;
            }

            $remaining = $this->deadline - $this->clock->microtime();
            if ($remaining <= 0) {
                $this->terminateTree();
                $timedOut = true;
                $this->readCollect($this->pipes[1], $stdout, $delta, 'stdout', null, $matched);
                $this->readCollect($this->pipes[2], $stderr, $delta, 'stderr', null, $matched);
                break;
            }

            $this->wait(min(self::ABORT_POLL_SECONDS, $remaining));
        }

        $this->releasePipes();

        $fromClose = $this->closeProcess();
        $this->removeCaptureFiles();
        $exitCode ??= $fromClose;

        $result = ['exit' => $exitCode];
        if ($timedOut) {
            $result['timeout'] = true;
        }
        if ($cancelled) {
            $result['cancelled'] = true;
            $result['elapsed'] = $this->clock->elapsed($this->startedAt);
        }
        if ($matched) {
            $result['matched'] = true;
        }
        if ($halted) {
            $result['halted'] = true;
        }
        $result['stderr'] = $this->finish($stderr['buffer'], 'stderr');
        $result['stdout'] = $this->finish($stdout['buffer'], 'stdout');

        return $result;
    }

    /**
     * Collect stdout lines; stop after `$maxLines` (+1 to detect truncation).
     *
     * `$delta` is dispatched per chunk with `chunk` and `channel` filled here.
     * Stderr is spilled for this call.
     *
     * @return array{exit: int, lines: list<string>, truncated?: bool, timeout?: bool, cancelled?: bool, elapsed?: float, stderr?: string}
     */
    public function lines(ToolResultDelta $delta, int $maxLines): array
    {
        if ($maxLines < 1) {
            Exception::raise('maxLines must be at least 1.');
        }

        $lines = [];
        $lineRemainder = '';
        $truncated = false;
        $stoppedEarly = false;
        $timedOut = false;
        $cancelled = false;
        $exitCode = null;
        $matched = false;
        $stderr = ['buffer' => '', 'capped' => false];

        while (true) {
            $this->readStdoutLines($this->pipes[1], $lines, $lineRemainder, $maxLines, $truncated, $stoppedEarly, $delta);
            $this->readCollect($this->pipes[2], $stderr, $delta, 'stderr', null, $matched);

            if ($this->stopped()) {
                $this->terminateTree();
                $this->readStdoutLines($this->pipes[1], $lines, $lineRemainder, $maxLines, $truncated, $stoppedEarly, $delta);
                $this->readCollect($this->pipes[2], $stderr, $delta, 'stderr', null, $matched);
                $exitCode = self::CANCELLED_EXIT_CODE;
                $cancelled = true;
                break;
            }

            if ($stoppedEarly) {
                $this->terminateTree();
                $this->readStdoutLines($this->pipes[1], $lines, $lineRemainder, $maxLines, $truncated, $stoppedEarly, $delta);
                $this->readCollect($this->pipes[2], $stderr, $delta, 'stderr', null, $matched);
                break;
            }

            $status = proc_get_status($this->process);
            if (!$status['running']) {
                $exitCode = $this->exitCodeFromStatus($status) ?? $exitCode;
                $this->readStdoutLines($this->pipes[1], $lines, $lineRemainder, $maxLines, $truncated, $stoppedEarly, $delta);
                $this->readCollect($this->pipes[2], $stderr, $delta, 'stderr', null, $matched);
                break;
            }

            $remaining = $this->deadline - $this->clock->microtime();
            if ($remaining <= 0) {
                $this->terminateTree();
                $timedOut = true;
                $this->readStdoutLines($this->pipes[1], $lines, $lineRemainder, $maxLines, $truncated, $stoppedEarly, $delta);
                $this->readCollect($this->pipes[2], $stderr, $delta, 'stderr', null, $matched);
                break;
            }

            $this->wait(min(self::ABORT_POLL_SECONDS, $remaining));
        }

        if ($lineRemainder !== '' && !$truncated) {
            $tail = rtrim($lineRemainder, "\r");
            if ($tail !== '') {
                $lines[] = $tail;
            }
        }

        $this->releasePipes();

        $fromClose = $this->closeProcess();
        $this->removeCaptureFiles();
        $exitCode ??= $fromClose;
        $stderrText = $this->finish($stderr['buffer'], 'stderr');

        $result = ['exit' => $exitCode];
        if ($timedOut) {
            $result['timeout'] = true;
        }
        if ($cancelled) {
            $result['cancelled'] = true;
            $result['elapsed'] = $this->clock->elapsed($this->startedAt);
        }
        if ($truncated) {
            $result['truncated'] = true;
        }
        if ($stderrText !== '') {
            $result['stderr'] = $stderrText;
        }
        $result['lines'] = array_slice($lines, 0, $maxLines);

        return $result;
    }

    /**
     * Guidance: an unusable directory is a configuration error — reported as "not absolute" when it is not.
     */
    protected function directory(string $cwd): string
    {
        if (!Path::isAbsolute($cwd)) {
            Exception::raise('Working directory must be absolute, got "{cwd}".', ['cwd' => $cwd]);
        }

        if (!is_dir($cwd)) {
            Exception::raise('Working directory does not exist.');
        }

        return $cwd;
    }

    /**
     * @param list<string> $command
     * @param array<int, array{0: string, 1: string}> $descriptors
     * @param array<int, resource>|null $pipes
     *
     * @return resource
     */
    protected function open(array $command, array $descriptors, ?array &$pipes, string $cwd)
    {
        $process = @proc_open($this->command($command), $descriptors, $pipes, $cwd, null, ['bypass_shell' => true]);
        if (is_resource($process)) {
            return $process;
        }

        Exception::raise(
            'Failed to start process "{command}"{cwd}.',
            [
                'command' => $command[0] ?? '',
                'cwd' => ' in ' . $cwd,
            ],
        );
    }

    /**
     * CreateProcess starts a PE binary, not a `#!` script. Hand that script to Git bash.
     *
     * @param list<string> $command
     *
     * @return list<string>
     */
    protected function command(array $command): array
    {
        if (PHP_OS_FAMILY !== 'Windows' || !isset($command[0]) || $command[0] === '') {
            return $command;
        }

        $script = $this->shebang($command[0]);
        if ($script === null) {
            return $command;
        }

        $bash = $this->gitBash();
        if (!is_file($bash)) {
            return $command;
        }

        return [$bash, $script, ...array_slice($command, 1)];
    }

    /** Absolute path of a shebang script named `$program`, on `PATH` when `$program` is a bare name. */
    protected function shebang(string $program): ?string
    {
        $candidates = [$program];
        if (!str_contains($program, '/') && !str_contains($program, '\\') && !str_contains($program, ':')) {
            $candidates = [];
            foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
                if ($dir === '') {
                    continue;
                }
                $candidates[] = $dir . DIRECTORY_SEPARATOR . $program;
            }
        }

        foreach ($candidates as $path) {
            if (!is_file($path)) {
                continue;
            }
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                continue;
            }
            $prefix = fread($handle, 2);
            fclose($handle);
            if ($prefix === '#!') {
                return $path;
            }
            if ($path === $program) {
                return null;
            }
        }

        return null;
    }

    /** System32 `bash.exe` is the WSL stub. Git bash is the one that runs `#!` scripts. */
    protected function gitBash(): string
    {
        $root = getenv('ProgramFiles');

        return (is_string($root) && $root !== '' ? $root : 'C:\\Program Files') . '\\Git\\bin\\bash.exe';
    }

    /** Configured wall-clock cap in seconds. Override in tests for a short bound. */
    public function timeout(): int
    {
        return max(1, $this->timeout);
    }

    /** In-memory collection cap in bytes. Override in tests for a cheap ceiling. */
    public function ceilingBytes(): int
    {
        return max(1, $this->ceiling) * 1024;
    }

    public function wait(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        if (extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0) {
            \Swoole\Coroutine::sleep($seconds);

            return;
        }

        usleep((int)($seconds * 1_000_000));
    }

    protected function stopped(): bool
    {
        return $this->agent->isCancelled() || $this->call->cancelled;
    }

    /** Ceiling-capped text, clipped in memory or spilled under this call. */
    protected function finish(string $data, string $channel): string
    {
        if (!$this->spill) {
            return $data;
        }

        $datadir = $this->agent->thread()->datadir;
        if ($datadir === null) {
            return Spiller::clipped($data, $this->budget, $this->keep);
        }

        return $this->spiller->spill(
            $datadir,
            AbstractTool::spillFile($this->call->id, $channel),
            $data,
            $this->budget,
            $this->keep,
        );
    }

    /**
     * Map `proc_close()` return to a process exit code.
     *
     * Windows (incl. Git Bash PHP): already a raw exit code.
     * Unix: may be a wait(2) status with the code in the high byte.
     * Prefer `exitCodeFromStatus()` when available — Swoole-hooked
     * `proc_close()` often returns 0 even on failure.
     */
    protected function decodeClosedExitCode(int $closed): int
    {
        if ($closed === -1) {
            return -1;
        }

        return ProcessTree::exitCode($closed, $this->procCloseReturnsRawExitCode());
    }

    /** Override in tests to force Unix/Windows decode branches. */
    protected function procCloseReturnsRawExitCode(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    protected function releasePipes(): void
    {
        fclose($this->pipes[1]);
        fclose($this->pipes[2]);
    }

    /** Unlink after the child closes the files. Windows refuses the delete while they are open. */
    protected function removeCaptureFiles(): void
    {
        foreach ($this->captureFiles as $file) {
            @unlink($file);
        }
        $this->captureFiles = [];
    }

    protected function assertValidPattern(string $pattern): void
    {
        if (@preg_match('#' . str_replace('#', '\#', $pattern) . '#s', '') === false) {
            Exception::raise('Invalid pattern regex.');
        }
    }

    /**
     * @param resource $pipe
     * @param array{buffer: string, capped: bool} $stream
     * @param null|callable(string): bool $halt
     *
     * @return string newest bytes kept for pattern matching (a rolling window)
     */
    protected function readCollect(
        $pipe,
        array &$stream,
        ?ToolResultDelta $delta,
        string $channel,
        ?string $pattern,
        bool &$matched,
        string $window = '',
        ?callable $halt = null,
        ?bool &$halted = null,
    ): string {
        if (!is_resource($pipe) || feof($pipe)) {
            return $window;
        }

        $cap = $this->ceilingBytes();
        while (true) {
            $chunk = fread($pipe, 8192);
            if ($chunk === false || $chunk === '') {
                $this->clearFileEof($pipe);

                return $window;
            }

            $this->emitDelta($delta, $chunk, $channel);
            if (!$stream['capped']) {
                $room = $cap - strlen($stream['buffer']);
                if (strlen($chunk) > $room) {
                    $stream['buffer'] .= substr($chunk, 0, $room);
                    $stream['buffer'] .= Spiller::ceilingExceeded($cap);
                    $stream['capped'] = true;
                } else {
                    $stream['buffer'] .= $chunk;
                    if (strlen($stream['buffer']) >= $cap) {
                        $stream['buffer'] .= Spiller::ceilingExceeded($cap);
                        $stream['capped'] = true;
                    }
                }
            }

            if ($halt !== null && $halt($stream['buffer']) === true) {
                $halted = true;
                $this->clearFileEof($pipe);

                return $window;
            }

            if ($pattern === null || $matched) {
                continue;
            }

            $window = self::patternWindow($window . $chunk);
            if (preg_match('#' . str_replace('#', '\#', $pattern) . '#s', $window) === 1) {
                $matched = true;
                $this->clearFileEof($pipe);

                return $window;
            }
        }
    }

    /**
     * A capture file stays at EOF after a short read. Rewind the flag so the next poll sees later bytes.
     *
     * @param resource $pipe
     */
    protected function clearFileEof($pipe): void
    {
        $meta = stream_get_meta_data($pipe);
        if (($meta['seekable'] ?? false) !== true) {
            return;
        }
        $pos = ftell($pipe);
        if (is_int($pos)) {
            fseek($pipe, $pos, SEEK_SET);
        }
    }

    /** Keep the newest {@see self::PATTERN_WINDOW_BYTES} of a stream for pattern matching. */
    protected static function patternWindow(string $window): string
    {
        if (strlen($window) <= self::PATTERN_WINDOW_BYTES) {
            return $window;
        }

        return substr($window, -self::PATTERN_WINDOW_BYTES);
    }

    /**
     * @param array{buffer: string, capped: bool} $stdout
     * @param array{buffer: string, capped: bool} $stderr
     */
    protected function abort(?ToolResultDelta $delta, array &$stdout, array &$stderr): int
    {
        $this->terminateTree();
        $matched = false;
        $this->readCollect($this->pipes[1], $stdout, $delta, 'stdout', null, $matched);
        $this->readCollect($this->pipes[2], $stderr, $delta, 'stderr', null, $matched);

        return self::CANCELLED_EXIT_CODE;
    }

    /**
     * @param resource $pipe
     * @param list<string> $lines
     */
    protected function readStdoutLines(
        $pipe,
        array &$lines,
        string &$lineRemainder,
        int $maxLines,
        bool &$truncated,
        bool &$stoppedEarly,
        ?ToolResultDelta $delta,
    ): void {
        if (!is_resource($pipe) || $stoppedEarly) {
            return;
        }
        $this->clearFileEof($pipe);
        if (feof($pipe)) {
            return;
        }

        while (true) {
            $chunk = fread($pipe, 8192);
            if ($chunk === false || $chunk === '') {
                $this->clearFileEof($pipe);

                return;
            }

            $lineRemainder .= $chunk;
            $this->emitDelta($delta, $chunk, 'stdout');

            while (($position = strpos($lineRemainder, "\n")) !== false) {
                $line = rtrim(substr($lineRemainder, 0, $position), "\r");
                $lineRemainder = substr($lineRemainder, $position + 1);
                if ($line === '') {
                    continue;
                }

                $lines[] = $line;
                if (count($lines) > $maxLines) {
                    $truncated = true;
                    $stoppedEarly = true;

                    return;
                }
            }
        }
    }

    protected function emitDelta(?ToolResultDelta $delta, string $chunk, string $channel): void
    {
        if ($delta === null || $channel === '' || $chunk === '') {
            return;
        }

        $delta->chunk = $chunk;
        $delta->channel = $channel;
        $this->eventDispatcher->dispatch($delta);
    }

    /**
     * Wait briefly for exit (escalate kill if needed), then `proc_close` with a bound.
     */
    protected function closeProcess(): int
    {
        $exitCode = null;
        $waitUntil = $this->clock->microtime() + 1.0;
        while (true) {
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                $exitCode = $this->exitCodeFromStatus($status);
                break;
            }
            if ($this->clock->microtime() >= $waitUntil) {
                $this->terminateTree();
                $this->wait(0.05);
                $status = proc_get_status($this->process);
                $exitCode = $this->exitCodeFromStatus($status);
                break;
            }
            $this->wait(0.02);
        }

        $closed = proc_close($this->process);
        if ($exitCode !== null) {
            return $exitCode;
        }

        return $this->decodeClosedExitCode($closed);
    }

    /**
     * `proc_get_status()` exposes `exitcode` only on the first call after exit.
     *
     * @param array<string, mixed> $status
     */
    protected function exitCodeFromStatus(array $status): ?int
    {
        if (($status['running'] ?? true) === true) {
            return null;
        }

        $code = $status['exitcode'] ?? -1;
        if (!is_int($code) || $code < 0) {
            return null;
        }

        return $code;
    }

    /**
     * Stop this process and its descendants.
     *
     * The tree kill is {@see ProcessTree}. `proc_terminate` still runs so the `proc_open` handle is released.
     */
    protected function terminateTree(): void
    {
        $status = proc_get_status($this->process);
        $pid = is_int($status['pid'] ?? null) ? $status['pid'] : 0;
        ProcessTree::terminate($pid, 0.1, $this->wait(...));
        proc_terminate($this->process, 9);
    }
}
