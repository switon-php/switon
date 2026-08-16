<?php

declare(strict_types=1);

namespace Switon\Tooling\Tool;

use Switon\Ai\AgentInterface;
use Switon\Ai\Event\ToolResultDelta;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ClockInterface;
use Switon\Core\MakerInterface;
use Switon\Core\ProcessTree;
use Switon\Tooling\AbstractTool;
use Switon\Tooling\Exception;
use Switon\Tooling\ProcessInterface;
use Switon\Tooling\Spiller;

use function fclose;
use function fopen;
use function fseek;
use function is_string;
use function max;
use function preg_match;
use function str_replace;
use function stream_get_contents;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;

/**
 * Tool `bash`. One fresh shell per call.
 *
 * Guidance: Unix needs `bash` on PATH; Windows needs Git Bash (`bash` and `nohup`). Set `$bash` only to pin another binary.
 */
class BashTool extends AbstractTool
{
    #[Autowired] protected MakerInterface $maker;
    #[Autowired] protected ClockInterface $clock;

    /** Bash executable (PATH name or absolute). Default `bash` (Git Bash on Windows). */
    #[Autowired] protected string $bash = 'bash';

    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Each call is a fresh shell; chain with `&&`. Prefer a dedicated tool when one fits (use `rm` tool for deletes, not shell rm). Keep command output small (flags/pipes); oversized output is spilled for the recall tool, path starts with @thread/. Requires bash (Git Bash on Windows).',
            properties: [
                'command' => [
                    'type' => 'string',
                ],
                'cwd' => [
                    'type' => 'string',
                    'description' => 'Absolute directory. Defaults to workspace, else the system temp directory.',
                ],
                'timeout' => [
                    'type' => 'number',
                    'description' => 'Wall-clock timeout in seconds.',
                    'default' => 3600,
                ],
                'pattern' => [
                    'type' => 'string',
                    'description' => 'Return when stdout/stderr matches this regex.',
                ],
                'daemon' => [
                    'type' => 'boolean',
                    'description' => 'Detach; process is not managed. Optional pattern: wait for match, keep alive, return pid.',
                    'default' => false,
                ],
            ],
            required: ['command'],
        );
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        // Do not trim args — use as given.
        $command = $arguments['command'];
        $cwd = $arguments['cwd'] ?? $agent->workspace();
        $pattern = $arguments['pattern'] ?? null;
        $timeout = (int)$arguments['timeout'];
        $daemon = (bool)$arguments['daemon'];
        $script = $command;
        $log = null;

        if ($daemon) {
            // One bash script on Unix and Git Bash. Quotes are bash quotes, not the host shell.
            $sink = '/dev/null';
            if ($pattern !== null) {
                $log = tempnam(sys_get_temp_dir(), 'switon-bash-');
                if ($log === false) {
                    Exception::raise('Failed to create daemon log file.');
                }
                $sink = $log;
            }
            $script = 'nohup ' . "'" . str_replace("'", "'\\''", $this->bash) . "'"
                . ' -c ' . "'" . str_replace("'", "'\\''", $command) . "'"
                . ' >' . "'" . str_replace("'", "'\\''", $sink) . "'"
                . ' 2>&1 & echo $!';
        }

        /** @var ProcessInterface $process */
        $process = $this->maker->make(ProcessInterface::class, [
            'agent' => $agent,
            'call' => $call,
        ]);
        $startedAt = $this->clock->microtime();
        $result = $process->start([$this->bash, '-lc', $script], $cwd, $timeout)
            ->output(new ToolResultDelta($this, $call->id, $call->name, $agent, ''), $daemon ? null : $pattern);
        if (!$daemon) {
            return $result;
        }

        $pid = (int)trim($result['stdout']);
        $result['daemon'] = true;
        $result['stdout'] = '';
        if ($pid > 0) {
            $result['pid'] = $pid;
        }
        if ($log === null) {
            return $result;
        }

        $deadline = $startedAt + max(1, $timeout);
        $content = '';
        $window = '';
        $capped = false;
        $cap = $process->ceilingBytes();
        $offset = 0;
        $dead = false;
        $regex = '#' . str_replace('#', '\#', $pattern) . '#s';

        try {
            while (true) {
                $chunk = '';
                $handle = @fopen($log, 'rb');
                if ($handle !== false) {
                    try {
                        if ($offset === 0 || fseek($handle, $offset) === 0) {
                            $read = stream_get_contents($handle);
                            $chunk = is_string($read) ? $read : '';
                        }
                    } finally {
                        fclose($handle);
                    }
                }
                if ($chunk !== '') {
                    $this->rememberDaemonOutput($chunk, $content, $capped, $cap, $window);
                    $offset += strlen($chunk);
                }

                // Invalid regex → false (no match). The window matches {@see Process} so a hit past the ceiling still counts.
                if (@preg_match($regex, $window) === 1) {
                    $result['matched'] = true;
                    break;
                }
                if ($dead) {
                    $result['exited'] = true;
                    break;
                }
                if ($agent->isCancelled() || $call->cancelled) {
                    $result['cancelled'] = true;
                    $result['elapsed'] = $this->clock->elapsed($startedAt);
                    break;
                }
                if ($pid > 0 && !ProcessTree::alive($pid)) {
                    $dead = true;
                    continue;
                }

                $remaining = $deadline - $this->clock->microtime();
                if ($remaining <= 0) {
                    $result['timeout'] = true;
                    break;
                }
                $process->wait(min(0.1, $remaining));
            }
        } finally {
            @unlink($log);
        }

        $result['stdout'] = $this->spillField($content, $call->id, $agent, 'stdout');

        return $result;
    }

    /**
     * Keep at most `$cap` bytes for the returned body. Matching uses the newest {@see ProcessInterface::PATTERN_WINDOW_BYTES} bytes, same as {@see Process::output()}.
     */
    protected function rememberDaemonOutput(string $chunk, string &$content, bool &$capped, int $cap, string &$window): void
    {
        if (!$capped) {
            $room = $cap - strlen($content);
            if (strlen($chunk) > $room) {
                if ($room > 0) {
                    $content .= substr($chunk, 0, $room);
                }
                $content .= Spiller::ceilingExceeded($cap);
                $capped = true;
            } else {
                $content .= $chunk;
                if (strlen($content) >= $cap) {
                    $content .= Spiller::ceilingExceeded($cap);
                    $capped = true;
                }
            }
        }

        $window .= $chunk;
        if (strlen($window) > ProcessInterface::PATTERN_WINDOW_BYTES) {
            $window = substr($window, -ProcessInterface::PATTERN_WINDOW_BYTES);
        }
    }
}
