<?php

declare(strict_types=1);

namespace Switon\Core;

use function fclose;
use function fopen;
use function function_exists;
use function is_int;
use function is_resource;
use function is_string;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function rewind;
use function stream_get_contents;

use const PHP_OS_FAMILY;
use const STDERR;
use const STDIN;
use const STDOUT;

/**
 * Run an external program from an argument list.
 *
 * Every OS starts the program with `proc_open`. A shell string is not accepted:
 * pipes and `&&` stay at the call site.
 */
class Program
{
    /**
     * Run `$argv` and return its stdout and exit code.
     *
     * Stderr is discarded. `$mergeStderr` folds it into stdout for callers that
     * read diagnostics from either stream, such as `php -l`.
     *
     * Code `-1` means the program did not start.
     *
     * @param list<string> $argv
     *
     * @return array{output: string, code: int}
     */
    public static function run(array $argv, bool $mergeStderr = false): array
    {
        if ($argv === [] || !function_exists('proc_open')) {
            return ['output' => '', 'code' => -1];
        }

        // Stdout is captured here. Merged stderr writes to the same stream.
        $captured = fopen('php://temp', 'w+');
        if (!is_resource($captured)) {
            return ['output' => '', 'code' => -1];
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => $captured,
            2 => $mergeStderr ? $captured : ['file', self::nullDevice(), 'w'],
        ];
        $pipes = [];
        $process = @proc_open($argv, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            fclose($captured);

            return ['output' => '', 'code' => -1];
        }

        fclose($pipes[0]);
        $code = ProcessTree::exitCode(proc_close($process));
        rewind($captured);
        $output = stream_get_contents($captured);
        fclose($captured);

        return [
            'output' => is_string($output) ? $output : '',
            'code' => $code,
        ];
    }

    /**
     * Run `$argv` on this process's standard streams.
     *
     * Code `-1` means the program did not start.
     *
     * @param list<string> $argv
     */
    public static function passthru(array $argv): int
    {
        if ($argv === [] || !function_exists('proc_open')) {
            return -1;
        }

        $pipes = [];
        $process = @proc_open($argv, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            return -1;
        }

        return ProcessTree::exitCode(proc_close($process));
    }

    /**
     * Start `$argv` and return its pid.
     *
     * The process keeps running after this call returns. The process handle is not
     * retained. `0` means it did not start.
     *
     * @param list<string> $argv
     */
    public static function start(array $argv): int
    {
        if ($argv === [] || !function_exists('proc_open')) {
            return 0;
        }

        $null = self::nullDevice();
        $pipes = [];
        $process = @proc_open(
            $argv,
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            return 0;
        }

        $status = proc_get_status($process);
        $pid = is_int($status['pid'] ?? null) ? $status['pid'] : 0;
        if ($pid <= 0 || ($status['running'] ?? false) !== true) {
            proc_close($process);

            return 0;
        }

        return $pid;
    }

    protected static function nullDevice(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    }
}
