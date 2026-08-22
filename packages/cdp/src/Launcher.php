<?php

declare(strict_types=1);

namespace Switon\Cdp;

use Switon\Cdp\Exception;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ClockInterface;
use Switon\Core\Exception\CreateDirectoryFailedException;
use Switon\Core\FilesystemInterface;
use Switon\Core\ProcessTree;
use Throwable;

use function array_slice;
use function base64_encode;
use function ctype_digit;
use function escapeshellarg;
use function exec;
use function fclose;
use function flock;
use function fopen;
use function fsockopen;
use function getenv;
use function iconv;
use function implode;
use function is_string;
use function max;
use function preg_match;
use function shell_exec;
use function str_contains;
use function str_replace;
use function sys_get_temp_dir;
use function trim;

use const LOCK_EX;
use const LOCK_UN;
use const PHP_OS_FAMILY;

/**
 * Locates Chrome/Chromium and ensures a debugging port is listening.
 *
 * Role: process lifecycle only — CDP sessions live in {@see \Switon\Cdp\Session}.
 * Prefer attach when the port is already open; `ensure()` only launches as fallback.
 *
 * Launch stays detached and does not use `proc_open()` — Swoole coroutines corrupt proc_open pipe
 * FDs ("Bad file descriptor"), which killed API workers during web_search. Unix backgrounds a shell;
 * Windows uses `Start-Process`, which also returns a pid outside the PHP process.
 */
class Launcher
{
    #[Autowired] protected ClockInterface $clock;
    #[Autowired] protected FilesystemInterface $filesystem;

    /**
     * PIDs of Chrome processes this launcher started, keyed by debugging port.
     *
     * Role: ownership is per port, because one container resolves a single launcher for both
     * the headless and the headed service. A port absent from this map was attached to, not started.
     *
     * @var array<int, int>
     */
    protected array $ownedPids = [];

    /**
     * @param list<string> $extraArgs
     */
    public function ensure(
        int $port,
        string $userDataDir,
        bool $headless,
        string $executable,
        float $startupTimeout = 15.0,
        array $extraArgs = [],
    ): void {
        if ($this->portOpen($port)) {
            return;
        }

        // Serialize launches — parallel web_search coroutines must not double-start Chrome.
        $lockPath = sys_get_temp_dir() . '/switon-browser-launch-' . $port . '.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock !== false) {
            flock($lock, LOCK_EX);
            if ($this->portOpen($port)) {
                flock($lock, LOCK_UN);
                fclose($lock);

                return;
            }
        }

        try {
            $this->launchProcess($port, $userDataDir, $headless, $executable, $startupTimeout, $extraArgs);
        } finally {
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** Whether a visible window can open here. macOS and Windows always have a session. */
    protected static function hasDisplay(): bool
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return true;
        }

        return (getenv('DISPLAY') ?: '') !== '' || (getenv('WAYLAND_DISPLAY') ?: '') !== '';
    }

    /**
     * @param list<string> $extraArgs
     */
    protected function launchProcess(
        int $port,
        string $userDataDir,
        bool $headless,
        string $executable,
        float $startupTimeout,
        array $extraArgs,
    ): void {
        $binary = $executable !== '' ? $executable : Paths::detect();
        if ($binary === '') {
            Exception::raise('Chrome/Chromium executable not found. Set browser.executable or install Chrome.');
        }

        if ($userDataDir === '') {
            Exception::raise('browser.userDataDir must not be empty.');
        }

        if (!$headless && !self::hasDisplay()) {
            Exception::raise(
                'Headed Chrome needs a display, but neither DISPLAY nor WAYLAND_DISPLAY is set on this Linux host. Use the headless service here, or start Chrome from a desktop session.',
            );
        }

        if (!$this->filesystem->isDir($userDataDir)) {
            try {
                $this->filesystem->mkdir($userDataDir, 0700);
            } catch (CreateDirectoryFailedException) {
                Exception::raise('Failed to create Chrome user-data-dir "{dir}".', [
                    'dir' => Paths::displayPath($userDataDir),
                ]);
            }
        }

        // Non-default --user-data-dir is required for DevTools listening on modern Chrome.
        // Deliberately no `--remote-allow-origins=*`: this client never sends an `Origin` header,
        // and the wildcard would let any local web page open a DevTools socket on this profile.
        $args = [
            $binary,
            '--remote-debugging-port=' . $port,
            '--user-data-dir=' . $userDataDir,
            '--no-first-run',
            '--no-default-browser-check',
        ];

        if ($headless) {
            $args[] = '--headless=new';
            $args[] = '--disable-gpu';
        }

        foreach ($extraArgs as $arg) {
            $arg = trim((string) $arg);
            if ($arg !== '') {
                $args[] = $arg;
            }
        }

        $args[] = 'about:blank';

        $pid = $this->launchDetached($args, $headless);
        if ($pid <= 0) {
            Exception::raise('Failed to start Chrome: {cmd}', ['cmd' => Paths::displayPath($binary)]);
        }
        $this->ownedPids[$port] = $pid;

        $deadline = $this->clock->microtime() + max(1.0, $startupTimeout);
        while ($this->clock->microtime() < $deadline) {
            if (!$this->pidAlive($pid)) {
                unset($this->ownedPids[$port]);
                Exception::raise(
                    'Chrome exited during startup (port {port}). Likely causes: another Chrome holds the same user-data-dir, or a headed window has no display. Quit the other window, use the matching mode profile (~/.switon/chrome/headless vs headed), or run headless on a host without a desktop.',
                    ['port' => $port],
                );
            }
            if ($this->portOpen($port)) {
                return;
            }
            $this->clock->sleep(0.1);
        }

        unset($this->ownedPids[$port]);
        Exception::raise('Chrome debugging port {port} did not open within {seconds}s.', [
            'port' => $port,
            'seconds' => $startupTimeout,
        ]);
    }

    /**
     * Whether the browser listening on `$port` was started by this launcher.
     *
     * A browser that was attached to — the visible window a human opened to sign in — belongs to
     * someone else, and quits with its owner.
     */
    public function ownsProcess(int $port): bool
    {
        $pid = $this->ownedPids[$port] ?? 0;

        return $pid > 0 && $this->pidAlive($pid);
    }

    /** Quit the browser this launcher started on `$port`. A port it attached to is left alone. */
    public function stopOwnedProcess(int $port): void
    {
        $pid = $this->ownedPids[$port] ?? 0;
        unset($this->ownedPids[$port]);
        if ($pid <= 0 || !$this->pidAlive($pid)) {
            return;
        }

        $this->signalPid($pid);
    }

    /**
     * Bring the Chrome window listening on `$port` in front of other applications.
     */
    public function foreground(int $port): void
    {
        $pid = 0;
        foreach ($this->listenerPids($port) as $candidate) {
            if ($candidate > 0 && $this->pidAlive($candidate)) {
                $pid = $candidate;
                break;
            }
        }
        $owned = $this->ownedPids[$port] ?? 0;
        if ($pid <= 0 && $owned > 0 && $this->pidAlive($owned)) {
            $pid = $owned;
        }
        if ($pid <= 0 || !function_exists('shell_exec')) {
            return;
        }

        $this->raiseProcess($pid);
    }

    /**
     * Ask the window server to focus `$pid`.
     *
     * Chrome raising its own window is not enough on macOS: a process launched in the background
     * stays behind the app the human is using. The pid is the process that owns the debugging
     * port, so a second Chrome with the default profile is left alone.
     */
    protected function raiseProcess(int $pid): void
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $script = 'ObjC.import("AppKit");'
                . 'var app = $.NSRunningApplication.runningApplicationWithProcessIdentifier(' . $pid . ');'
                . 'if (app) app.activateWithOptions(2);';
            shell_exec('osascript -l JavaScript -e ' . escapeshellarg($script) . ' >/dev/null 2>&1');

            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $command = '(New-Object -ComObject WScript.Shell).AppActivate(' . $pid . ') | Out-Null';
            shell_exec('powershell.exe -NoProfile -NonInteractive -Command ' . escapeshellarg($command));

            return;
        }

        if (!self::hasDisplay()) {
            return;
        }

        shell_exec('command -v xdotool >/dev/null 2>&1 && xdotool search --pid ' . $pid . ' windowactivate >/dev/null 2>&1');
    }

    /** @phpstan-impure */
    public function portOpen(int $port): bool
    {
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.25);
        if ($fp === false) {
            return false;
        }
        fclose($fp);

        return true;
    }

    /**
     * Whether the process listening on `$port` was started with `--headless`.
     *
     * Null when its command line cannot be read.
     */
    public function listenerIsHeadless(int $port): ?bool
    {
        $headless = null;
        foreach ($this->listenerPids($port) as $pid) {
            $command = $this->commandLine($pid);
            if ($command === '') {
                continue;
            }
            if (str_contains($command, '--headless')) {
                return true;
            }
            $headless = false;
        }

        return $headless;
    }

    /**
     * @return list<int>
     */
    protected function listenerPids(int $port): array
    {
        if (!function_exists('exec')) {
            return [];
        }

        $output = [];
        if (PHP_OS_FAMILY === 'Windows') {
            exec('netstat -ano -p tcp | findstr :' . $port, $output);
            $pids = [];
            foreach ($output as $line) {
                if (preg_match('/:' . $port . '\s+\S+\s+LISTENING\s+(\d+)\s*$/', trim($line), $match) !== 1) {
                    continue;
                }
                $pids[] = (int) $match[1];
            }

            return $pids;
        }

        exec('lsof -nP -iTCP:' . $port . ' -sTCP:LISTEN -t 2>/dev/null', $output);
        $pids = [];
        foreach ($output as $line) {
            $line = trim($line);
            if (ctype_digit($line)) {
                $pids[] = (int) $line;
            }
        }

        return $pids;
    }

    protected function commandLine(int $pid): string
    {
        if ($pid <= 0 || !function_exists('exec')) {
            return '';
        }

        $output = [];
        if (PHP_OS_FAMILY === 'Windows') {
            exec('powershell.exe -NoProfile -NonInteractive -Command "(Get-CimInstance Win32_Process -Filter \'ProcessId=' . $pid . '\').CommandLine"', $output);
        } else {
            exec('ps -p ' . $pid . ' -ww -o args=', $output);
        }

        return trim(implode("\n", $output));
    }

    protected function signalPid(int $pid): void
    {
        if ($pid <= 0 || !ProcessTree::alive($pid)) {
            return;
        }

        ProcessTree::terminate($pid, 3.0, $this->clock->sleep(...));
    }

    /**
     * @param list<string> $args
     */
    protected function launchDetached(array $args, bool $headless): int
    {
        try {
            $pidRaw = PHP_OS_FAMILY === 'Windows'
                ? $this->startOnWindows($args, $headless)
                : $this->startOnUnix($args);
        } catch (Throwable) {
            $pidRaw = null;
        }

        return (int) trim((string) $pidRaw);
    }

    /**
     * @param list<string> $args
     */
    protected function startOnUnix(array $args): ?string
    {
        $cmd = '';
        foreach ($args as $arg) {
            $cmd .= ($cmd === '' ? '' : ' ') . escapeshellarg($arg);
        }

        $raw = shell_exec($cmd . ' >/dev/null 2>&1 & echo $!');

        return is_string($raw) ? $raw : null;
    }

    /**
     * @param list<string> $args
     */
    protected function startOnWindows(array $args, bool $headless): ?string
    {
        $file = str_replace("'", "''", $args[0]);
        $quoted = [];
        foreach (array_slice($args, 1) as $arg) {
            $quoted[] = "'" . str_replace("'", "''", $arg) . "'";
        }
        $argumentList = $quoted === [] ? '' : ' -ArgumentList @(' . implode(',', $quoted) . ')';
        $style = $headless ? 'Hidden' : 'Normal';
        $script = "Write-Output (Start-Process -FilePath '$file'$argumentList -WindowStyle $style -PassThru).Id";
        $utf16 = iconv('UTF-8', 'UTF-16LE', $script);
        if ($utf16 === false) {
            return null;
        }

        $raw = shell_exec('powershell.exe -NoProfile -NonInteractive -EncodedCommand ' . base64_encode($utf16));

        return is_string($raw) ? $raw : null;
    }

    /** @phpstan-impure */
    protected function pidAlive(int $pid): bool
    {
        return ProcessTree::alive($pid);
    }
}
