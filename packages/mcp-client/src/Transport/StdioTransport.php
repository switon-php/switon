<?php

declare(strict_types=1);

namespace Switon\McpClient\Transport;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Exception\JsonException;
use Switon\Core\Json;
use Switon\Core\ProcessTree;
use Switon\McpClient\Event\TransportRead;
use Switon\McpClient\Event\TransportWrite;
use Switon\McpClient\Exception\AbortedTransportException;
use Switon\McpClient\Exception\TransportException;

use function array_filter;
use function array_key_exists;
use function ctype_digit;
use function fclose;
use function feof;
use function fflush;
use function fgets;
use function fopen;
use function fread;
use function fseek;
use function ftell;
use function fwrite;
use function in_array;
use function is_array;
use function is_int;
use function is_resource;
use function is_scalar;
use function is_string;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function stream_select;
use function stream_set_blocking;
use function strlen;
use function strpos;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;
use function usleep;

/** Newline-delimited JSON-RPC over a child process. */
class StdioTransport implements StdioTransportInterface
{
    protected const float ABORT_POLL_SECONDS = 0.1;
    /** Stderr bytes kept for the next read-failure message. */
    protected const int STDERR_TAIL = 2048;
    /** Stderr bytes drained between stdout polls, so a chatty child cannot fill the pipe. */
    protected const int STDERR_DRAIN = 262144;

    #[Autowired] protected LoggerInterface $logger;
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;

    #[Autowired] protected string $command = '';
    /** @var list<string> */
    #[Autowired] protected array $args = [];
    /** @var array<string, string> */
    #[Autowired] protected array $env = [];
    #[Autowired] protected ?string $cwd = null;
    /** Per-read timeout in seconds. Fractional values are kept, so a test can wait milliseconds. */
    #[Autowired] protected float $readTimeout = 30;
    /** One stdout line. A server that never sends a newline cannot grow past this. */
    protected int $maxLineBytes = 8388608;

    /** @var resource|null */
    protected mixed $process = null;
    /** @var resource|null */
    protected mixed $stdin = null;
    /** @var resource|null */
    protected mixed $stdout = null;
    /** @var resource|null */
    protected mixed $stderr = null;
    protected string $stderrTail = '';
    protected string $stdoutPending = '';
    /** Windows captures stdout here. A pipe `fread()` blocks even when set non-blocking. */
    protected ?string $stdoutFile = null;
    /** Windows captures stderr here so a full pipe cannot stall the child. */
    protected ?string $stderrFile = null;

    public function open(): void
    {
        if ($this->stdin !== null) {
            return;
        }

        $command = array_merge([$this->command], $this->args);
        $stdoutFile = null;
        $stderrFile = null;
        if (PHP_OS_FAMILY === 'Windows') {
            $stdoutFile = tempnam(sys_get_temp_dir(), 'switon-mcp-out-');
            $stderrFile = tempnam(sys_get_temp_dir(), 'switon-mcp-err-');
            if ($stdoutFile === false || $stderrFile === false) {
                TransportException::raise('Failed to create MCP stdio capture files.');
            }
            $descriptorSpec = [
                0 => ['pipe', 'r'],
                1 => ['file', $stdoutFile, 'w'],
                2 => ['file', $stderrFile, 'w'],
            ];
        } else {
            $descriptorSpec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
        }
        // $_ENV is usually empty (variables_order defaults to GPCS), so merging it alone
        // would strip PATH and the child could not resolve its own command. Only scalar
        // entries qualify as environment variables; argv and argc are arrays.
        $env = $this->env === [] ? null : array_merge(
            array_filter($_SERVER, is_scalar(...)),
            $this->env,
        );

        $process = proc_open($command, $descriptorSpec, $pipes, $this->cwd, $env);
        if (!is_resource($process)) {
            if (is_string($stdoutFile)) {
                @unlink($stdoutFile);
            }
            if (is_string($stderrFile)) {
                @unlink($stderrFile);
            }
            TransportException::raise('Failed to start MCP stdio process "{command}".', [
                'command' => implode(' ', $command),
            ]);
        }

        $this->process = $process;
        $this->stdin = $pipes[0];
        if (is_string($stdoutFile) && is_string($stderrFile)) {
            $this->stdoutFile = $stdoutFile;
            $this->stderrFile = $stderrFile;
            $stdout = fopen($stdoutFile, 'rb');
            $stderr = fopen($stderrFile, 'rb');
            if ($stdout === false || $stderr === false) {
                if (is_resource($stdout)) {
                    fclose($stdout);
                }
                if (is_resource($stderr)) {
                    fclose($stderr);
                }
                $this->close();
                TransportException::raise('Failed to open MCP stdio capture files.');
            }
            $this->stdout = $stdout;
            $this->stderr = $stderr;
        } else {
            $this->stdout = $pipes[1];
            $this->stderr = $pipes[2];
            stream_set_blocking($this->stderr, false);
        }
        $this->stderrTail = '';
        stream_set_blocking($this->stdout, false);
    }

    public function send(array $message, callable $shouldAbort, ?callable $notificationHandler = null): ?array
    {
        if (!is_resource($this->stdin) || !is_resource($this->stdout)) {
            TransportException::raise('MCP stdio transport is not open.');
        }

        if ($shouldAbort()) {
            AbortedTransportException::raise('MCP stdio request aborted.');
        }

        $this->eventDispatcher->dispatch(new TransportWrite($message));

        fwrite($this->stdin, Json::stringify($message) . "\n");
        fflush($this->stdin);

        $requestId = $message['id'] ?? null;
        if ($requestId === null) {
            return null;
        }

        $stdout = $this->stdout;
        while (($line = $this->readLine($stdout, $shouldAbort)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            try {
                $response = Json::parse($line);
            } catch (JsonException) {
                $this->logStderr('Skipping invalid JSON line on MCP stdio transport.');
                continue;
            }

            if (!is_array($response)) {
                $this->logStderr('MCP stdio response must decode to an object.');
                continue;
            }

            if (!array_key_exists('id', $response)) {
                if ($notificationHandler !== null) {
                    $notificationHandler($response);
                }
                continue;
            }

            $this->eventDispatcher->dispatch(new TransportRead($response));

            if (!$this->idsMatch($response['id'], $requestId)) {
                continue;
            }

            return $response;
        }

        if (is_resource($this->process)) {
            $this->failRead('MCP stdio stdout closed.');
        }

        return null;
    }

    public function close(): void
    {
        if ($this->stdin !== null && is_resource($this->stdin)) {
            fclose($this->stdin);
        }
        if ($this->stdout !== null && is_resource($this->stdout)) {
            fclose($this->stdout);
        }

        if ($this->stderr !== null && is_resource($this->stderr)) {
            $this->drainStderr();
            if ($this->stderrTail !== '') {
                $this->logger->warning('MCP stdio process stderr: {stderr}', ['stderr' => trim($this->stderrTail)]);
            }
            fclose($this->stderr);
        }

        if ($this->process !== null && is_resource($this->process)) {
            // proc_close() waits for the child to exit. Kill it first so a hung server cannot block close().
            $status = proc_get_status($this->process);
            if ($status['running']) {
                $pid = is_int($status['pid'] ?? null) ? $status['pid'] : 0;
                ProcessTree::terminate($pid, 0.0);
                proc_terminate($this->process, 9);
            }
            proc_close($this->process);
        }

        $this->process = null;
        $this->stdin = null;
        $this->stdout = null;
        $this->stderr = null;
        $this->stderrTail = '';
        $this->stdoutPending = '';
        if ($this->stdoutFile !== null) {
            @unlink($this->stdoutFile);
            $this->stdoutFile = null;
        }
        if ($this->stderrFile !== null) {
            @unlink($this->stderrFile);
            $this->stderrFile = null;
        }
    }

    /**
     * @param resource $stdout
     */
    protected function readLine(mixed $stdout, callable $shouldAbort): string|false
    {
        if (is_resource($this->stderr)) {
            return $this->readProcess($stdout, $shouldAbort);
        }

        return $this->readStdout($stdout, $shouldAbort);
    }

    /**
     * A blocking stdout read lets stderr fill the pipe and stall the child.
     *
     * @param resource $stdout
     */
    protected function readProcess(mixed $stdout, callable $shouldAbort): string|false
    {
        stream_set_blocking($stdout, false);
        $deadline = microtime(true) + $this->readTimeout;

        while (microtime(true) < $deadline) {
            if ($shouldAbort()) {
                AbortedTransportException::raise('MCP stdio request aborted.');
            }

            $this->drainStderr();
            $chunk = PHP_OS_FAMILY === 'Windows'
                ? $this->pollStdout($stdout)
                : $this->selectStdout($stdout, $deadline);
            if ($chunk === null) {
                continue;
            }
            if ($chunk === false) {
                if ($this->stdoutPending === '') {
                    return false;
                }
                $line = $this->stdoutPending;
                $this->stdoutPending = '';

                return $line;
            }

            if (strlen($this->stdoutPending) + strlen($chunk) > $this->maxLineBytes) {
                $this->stdoutPending = '';
                $this->failRead('MCP stdio line exceeded {bytes} bytes.', ['bytes' => $this->maxLineBytes]);
            }
            $this->stdoutPending .= $chunk;
            $pos = strpos($this->stdoutPending, "\n");
            if ($pos === false) {
                continue;
            }
            $line = substr($this->stdoutPending, 0, $pos + 1);
            $this->stdoutPending = substr($this->stdoutPending, $pos + 1);

            return $line;
        }

        $this->failRead('MCP stdio read timed out after {seconds}s.', ['seconds' => $this->readTimeout]);
    }

    /**
     * Next stdout bytes from a selectable pipe.
     *
     * Null waits for another poll. False means the pipe closed.
     *
     * @param resource $stdout
     */
    protected function selectStdout(mixed $stdout, float $deadline): string|false|null
    {
        $read = [$stdout];
        if (is_resource($this->stderr)) {
            $read[] = $this->stderr;
        }
        $write = null;
        $except = null;
        $remainingUs = (int) max(0, ($deadline - microtime(true)) * 1_000_000);
        $waitUs = min((int) (self::ABORT_POLL_SECONDS * 1_000_000), max(1, $remainingUs));
        $seconds = intdiv($waitUs, 1_000_000);
        $microseconds = $waitUs % 1_000_000;
        $ready = stream_select($read, $write, $except, $seconds, $microseconds);
        if ($ready === false || $ready === 0) {
            return null;
        }

        if (is_resource($this->stderr) && in_array($this->stderr, $read, true)) {
            $this->drainStderr();
        }
        if (!in_array($stdout, $read, true)) {
            return null;
        }

        $chunk = fread($stdout, 8192);
        if (!is_string($chunk) || $chunk === '') {
            return feof($stdout) ? false : null;
        }

        return $chunk;
    }

    /**
     * Next stdout bytes from the capture file.
     *
     * An empty read is end-of-file only after the child has exited. `fseek()` clears the
     * file EOF mark so a later append is visible.
     *
     * @param resource $stdout
     */
    protected function pollStdout(mixed $stdout): string|false|null
    {
        $chunk = fread($stdout, 8192);
        if (is_string($chunk) && $chunk !== '') {
            return $chunk;
        }
        if (!$this->childRunning()) {
            // The exit flush can sit behind a sticky EOF from the short read above.
            $pos = ftell($stdout);
            if (is_int($pos)) {
                fseek($stdout, $pos, SEEK_SET);
            }
            $retry = fread($stdout, 8192);
            if (is_string($retry) && $retry !== '') {
                return $retry;
            }

            return false;
        }
        $pos = ftell($stdout);
        if (is_int($pos)) {
            fseek($stdout, $pos, SEEK_SET);
        }

        usleep((int) (self::ABORT_POLL_SECONDS * 1_000_000));

        return null;
    }

    protected function childRunning(): bool
    {
        if (!is_resource($this->process)) {
            return false;
        }

        $status = proc_get_status($this->process);

        return ($status['running'] ?? false) === true;
    }

    /** A numeric id may come back as the other JSON type. Compare the numeric form. */
    protected function idsMatch(mixed $responseId, int $requestId): bool
    {
        if ($responseId === $requestId) {
            return true;
        }

        return is_int($responseId) || (is_string($responseId) && ctype_digit($responseId))
            ? (int) $responseId === $requestId
            : false;
    }

    /**
     * @param resource $stdout
     */
    protected function readStdout(mixed $stdout, callable $shouldAbort): string|false
    {
        stream_set_blocking($stdout, true);
        $deadline = microtime(true) + $this->readTimeout;

        while (microtime(true) < $deadline) {
            if ($shouldAbort()) {
                AbortedTransportException::raise('MCP stdio request aborted.');
            }

            $remaining = max(0.001, $deadline - microtime(true));
            $wait = min(self::ABORT_POLL_SECONDS, $remaining);
            $seconds = (int) $wait;
            $microseconds = (int) round(($wait - $seconds) * 1_000_000);
            stream_set_timeout($stdout, $seconds, max(0, $microseconds));
            $line = fgets($stdout);
            if ($line !== false) {
                return $line;
            }

            if (!(stream_get_meta_data($stdout)['timed_out'] ?? false)) {
                return false;
            }
        }

        $this->failRead('MCP stdio read timed out after {seconds}s.', ['seconds' => $this->readTimeout]);
    }

    /** @param array<string, mixed> $context */
    protected function failRead(string $message, array $context = []): never
    {
        $this->drainStderr();
        $status = $this->processStatus();
        if ($status !== '') {
            $message .= ' {status}.';
            $context['status'] = $status;
        }
        $stderr = trim($this->stderrTail);
        if ($stderr !== '') {
            $message .= "\nstderr: {stderr}";
            $context['stderr'] = $stderr;
        }

        TransportException::raise($message, $context);
    }

    protected function processStatus(): string
    {
        if (!is_resource($this->process)) {
            return '';
        }

        // The first read after stdout EOF is often still running; PHP reaps on a later call.
        $status = proc_get_status($this->process);
        for ($try = 0; $status['running'] && $try < 5; $try++) {
            usleep(20_000);
            $status = proc_get_status($this->process);
        }
        if ($status['running']) {
            return 'Process still running';
        }

        return 'Process exited with ' . $status['exitcode'];
    }

    protected function drainStderr(): void
    {
        if (!is_resource($this->stderr)) {
            return;
        }
        if ($this->stderrFile !== null) {
            $pos = ftell($this->stderr);
            if (is_int($pos)) {
                fseek($this->stderr, $pos, SEEK_SET);
            }
        }

        $read = 0;
        while ($read < self::STDERR_DRAIN) {
            $chunk = fread($this->stderr, 8192);
            if (!is_string($chunk) || $chunk === '') {
                return;
            }
            $read += strlen($chunk);
            $this->stderrTail .= $chunk;
            if (strlen($this->stderrTail) > self::STDERR_TAIL) {
                $this->stderrTail = substr($this->stderrTail, -self::STDERR_TAIL);
            }
        }
    }

    protected function logStderr(string $message): void
    {
        $this->logger->warning($message);
    }
}
