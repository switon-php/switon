<?php

declare(strict_types=1);

namespace Switon\Lint;

use Switon\Core\Attribute\Autowired;
use Switon\Core\ProcessTree;
use Throwable;

use function is_int;
use function is_resource;
use function proc_get_status;
use function proc_terminate;
use function strlen;

/**
 * Minimal `proc_open` runner for lint drivers.
 *
 * In-memory stdout/stderr ceiling is 32 MiB. Structured diagnostics are spilled by the caller.
 */
class Process implements ProcessInterface
{
    #[Autowired] protected int $timeout = 120;
    /** In-memory stdout/stderr collection cap in MiB. */
    #[Autowired] protected int $ceiling = 32;

    public function run(
        array $command,
        callable $shouldAbort,
        ?string $workingDirectory = null,
        ?int $maxOutputBytes = null,
    ): array {
        $maxOutputBytes ??= $this->ceilingBytes();
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        try {
            $process = proc_open($command, $descriptors, $pipes, $workingDirectory, null, ['bypass_shell' => true]);
        } catch (Throwable $e) {
            Exception::raise(
                'Lint could not start process "{command}": {error}',
                ['command' => $command[0] ?? '', 'error' => $e->getMessage()],
                0,
                $e instanceof \Exception ? $e : null,
            );
        }

        if (!is_resource($process)) {
            Exception::raise('Failed to start process "{command}".', ['command' => $command[0] ?? '']);
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $this->timeout;

        while (true) {
            if ($shouldAbort()) {
                $this->terminate($process);
                proc_close($process);
                return ['exit' => 130, 'stdout' => $stdout, 'stderr' => $stderr];
            }

            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            stream_select($read, $write, $except, 0, 100000);

            foreach ($read as $pipe) {
                $chunk = fread($pipe, 8192);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($pipe === $pipes[1]) {
                    $stdout = $this->appendCapped($stdout, $chunk, $maxOutputBytes);
                } else {
                    $stderr = $this->appendCapped($stderr, $chunk, $maxOutputBytes);
                }
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) >= $deadline) {
                $this->terminate($process);
                proc_close($process);
                return ['exit' => 124, 'stdout' => $stdout, 'stderr' => $stderr];
            }
        }

        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = ProcessTree::exitCode(proc_close($process));

        return [
            'exit' => $exitCode,
            'stdout' => $this->appendCapped('', $stdout, $maxOutputBytes),
            'stderr' => $this->appendCapped('', $stderr, $maxOutputBytes),
        ];
    }

    /**
     * Stop the direct child and any process it has already spawned.
     *
     * @param resource $process
     */
    protected function terminate($process): void
    {
        $status = proc_get_status($process);
        $pid = is_int($status['pid'] ?? null) ? $status['pid'] : 0;
        ProcessTree::terminate($pid);
        proc_terminate($process, 9);
    }

    protected function ceilingBytes(): int
    {
        return max(1, $this->ceiling) * 1024 * 1024;
    }

    protected function appendCapped(string $buf, string $chunk, int $max): string
    {
        if (strlen($buf) >= $max) {
            return $buf;
        }
        $room = $max - strlen($buf);
        return $buf . (strlen($chunk) > $room ? substr($chunk, 0, $room) : $chunk);
    }
}
