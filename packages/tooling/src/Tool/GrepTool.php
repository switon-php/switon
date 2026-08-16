<?php

declare(strict_types=1);

namespace Switon\Tooling\Tool;

use Switon\Ai\AgentInterface;
use Switon\Ai\Event\ToolResultDelta;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Switon\Core\Attribute\Autowired;
use Switon\Core\MakerInterface;
use Switon\Core\Path;
use Switon\Tooling\AbstractTool;
use Switon\Tooling\ProcessInterface;

use function max;

/**
 * Tool `grep`.
 *
 * Guidance: `rg` must be on PATH. Set `$rg` only to pin another binary.
 */
class GrepTool extends AbstractTool
{
    #[Autowired] protected MakerInterface $maker;

    /** Wall-clock cap in seconds (not days — unlike {@see Process} default). */
    #[Autowired] protected int $timeout = 30;
    #[Autowired] protected int $maxCount = 200;
    /** ripgrep executable (name or absolute path). Default uses process PATH. */
    #[Autowired] protected string $rg = 'rg';

    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Search file contents with ripgrep.',
            properties: [
                'pattern' => [
                    'type' => 'string',
                    'description' => 'Search pattern (regex, or literal when literal=true).',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Absolute file or directory. Omit for the workspace root.',
                ],
                'glob' => [
                    'type' => 'string',
                    'description' => 'Include only matching paths, e.g. *.php (rg --glob).',
                ],
                'output_mode' => [
                    'type' => 'string',
                    'description' => 'content | files_with_matches | count.',
                    'enum' => ['content', 'files_with_matches', 'count'],
                    'default' => 'content',
                ],
                '-B' => [
                    'type' => 'integer',
                    'description' => 'Lines before each match (content mode).',
                    'minimum' => 0,
                ],
                '-A' => [
                    'type' => 'integer',
                    'description' => 'Lines after each match (content mode).',
                    'minimum' => 0,
                ],
                '-C' => [
                    'type' => 'integer',
                    'description' => 'Lines before and after each match (content mode).',
                    'minimum' => 0,
                ],
                'head_limit' => [
                    'type' => 'integer',
                    'description' => 'Max matches or files; omit for no cap.',
                    'minimum' => 1,
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Skip first N matches or files.',
                    'default' => 0,
                    'minimum' => 0,
                ],
                'multiline' => [
                    'type' => 'boolean',
                    'description' => 'Pattern may span lines.',
                    'default' => false,
                ],
                'literal' => [
                    'type' => 'boolean',
                    'description' => 'Treat pattern as literal string (rg --fixed-strings).',
                    'default' => false,
                ],
                'type' => [
                    'type' => 'string',
                    'description' => 'Language/file type, e.g. php (rg --type).',
                ],
                '-i' => [
                    'type' => 'boolean',
                    'description' => 'Case insensitive.',
                    'default' => false,
                ],
            ],
            required: ['pattern'],
        );
    }


    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        $pattern = $arguments['pattern'];
        $path = Path::absolute($arguments['path'] ?? $agent->workspace());
        $glob = $arguments['glob'] ?? null;
        $outputMode = $arguments['output_mode'] ?? 'content';
        $options = $this->options($arguments);
        $command = [$this->rg, '--color=never'];

        if ($outputMode === 'files_with_matches') {
            $command[] = '--files-with-matches';
        } elseif ($outputMode === 'count') {
            $command[] = '--count-matches';
        } else {
            $command[] = '--line-number';
            $command[] = '--no-heading';
        }

        // Per-file safety bound for emitted lines. Not applied in `count` mode: there `--max-count`
        // would cap the reported count itself, silently understating it with no truncation marker.
        if ($outputMode !== 'count') {
            $command[] = '--max-count=' . $this->maxCount;
        }

        if ($outputMode === 'content') {
            if (isset($options['-C'])) {
                $command[] = '--context=' . $options['-C'];
            } else {
                if (isset($options['-A'])) {
                    $command[] = '--after-context=' . $options['-A'];
                }

                if (isset($options['-B'])) {
                    $command[] = '--before-context=' . $options['-B'];
                }
            }
        }

        if ($options['multiline'] ?? false) {
            $command[] = '-U';
            $command[] = '--multiline-dotall';
        }

        if ($options['literal'] ?? false) {
            $command[] = '--fixed-strings';
        }

        if ($options['-i'] ?? false) {
            $command[] = '--ignore-case';
        }

        if (isset($options['type'])) {
            $command[] = '--type=' . $options['type'];
        }

        $command[] = $pattern;
        $command[] = $path;

        if ($glob !== null) {
            array_splice($command, -1, 0, ['--glob', $glob]);
        }

        $offset = max(0, (int)($options['offset'] ?? 0));
        $limit = isset($options['head_limit']) ? (int)$options['head_limit'] : null;
        $result = $this->process($agent, $call)->start(
            command: $command,
            cwd: $agent->workspace(),
            timeout: max(1, $this->timeout),
        )->output(
            delta: new ToolResultDelta($this, $call->id, $call->name, $agent, ''),
            halt: $this->halt($outputMode, $offset, $limit, isset($options['-A']) || isset($options['-B']) || isset($options['-C'])),
        );
        $stdout = $this->applyWindow(
            $result['stdout'],
            $outputMode,
            $offset,
            $limit,
        );

        $payload = ['exit' => $result['exit']];

        if (isset($result['timeout'])) {
            $payload['timeout'] = $result['timeout'];
        }

        if (isset($result['cancelled'])) {
            $payload['cancelled'] = $result['cancelled'];
        }

        if (isset($result['elapsed'])) {
            $payload['elapsed'] = $result['elapsed'];
        }

        $payload['stderr'] = $this->spillField($result['stderr'], $call->id, $agent, 'stderr');
        $payload['stdout'] = $this->spillField($stdout, $call->id, $agent, 'stdout');
        $payload['matches'] = $result['exit'] === 0 || ($result['halted'] ?? false) === true;

        return $payload;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{
     *     '-B'?: int,
     *     '-A'?: int,
     *     '-C'?: int,
     *     head_limit?: int,
     *     offset?: int,
     *     multiline?: bool,
     *     literal?: bool,
     *     type?: string,
     *     '-i'?: bool
     * }
     */
    protected function options(array $arguments): array
    {
        $options = [];
        foreach (['-B', '-A', '-C', 'head_limit', 'offset', 'type', 'multiline', 'literal', '-i'] as $key) {
            if (isset($arguments[$key])) {
                $options[$key] = $arguments[$key];
            }
        }

        return $options;
    }

    /**
     * Stop once the collected stdout holds the requested window.
     *
     * Content mode with context waits for one extra match so the lines before it stay in the buffer.
     * A null limit reads the whole stream.
     *
     * @return null|callable(string): bool
     */
    protected function halt(string $outputMode, int $offset, ?int $limit, bool $context): ?callable
    {
        if ($limit === null) {
            return null;
        }

        // The extra match is the end marker. `applyWindow()` drops it and keeps the context in front of it.
        $need = $offset + $limit + ($outputMode === 'content' && $context ? 1 : 0);

        $scanned = 0;
        $pending = '';
        $entries = 0;

        return function (string $stdout) use (&$scanned, &$pending, &$entries, $need, $outputMode): bool {
            $pending .= substr($stdout, $scanned);
            $scanned = strlen($stdout);
            while (($pos = strpos($pending, "\n")) !== false) {
                $line = substr($pending, 0, $pos);
                $pending = substr($pending, $pos + 1);
                if ($this->isWindowEntry($line, $outputMode)) {
                    $entries++;
                }
            }

            return $entries >= $need;
        };
    }

    protected function isWindowEntry(string $line, string $outputMode): bool
    {
        if ($line === '') {
            return false;
        }
        if ($outputMode !== 'content') {
            return true;
        }

        return preg_match('/^(?:.*?:)?\d+:/', $line) === 1;
    }

    /**
     * Skip `$offset` entries and keep at most `$limit` of them.
     *
     * Entries are match lines for content mode (context lines stay attached) and
     * whole lines (files or per-file counts) for the other modes.
     */
    protected function applyWindow(string $stdout, string $outputMode, int $offset, ?int $limit): string
    {
        $offset = max(0, $offset);
        if ($stdout === '' || ($offset === 0 && $limit === null)) {
            return $stdout;
        }

        if ($outputMode === 'content') {
            return $this->windowContent($stdout, $offset, $limit);
        }

        $lines = array_values(array_filter(
            explode("\n", rtrim($stdout, "\n")),
            static fn (string $line): bool => $line !== '',
        ));

        return implode("\n", array_slice($lines, $offset, $limit));
    }

    protected function windowContent(string $stdout, int $offset, ?int $limit): string
    {
        $lines = explode("\n", $stdout);
        $matchLines = [];
        foreach ($lines as $index => $line) {
            // Match line `[path:]line:text` vs context `[path-]line-text`.
            if ($line !== '' && preg_match('/^(?:.*?:)?\d+:/', $line) === 1) {
                $matchLines[] = $index;
            }
        }

        if ($offset >= count($matchLines)) {
            return '';
        }

        $start = $matchLines[$offset];
        if ($limit !== null && ($offset + $limit) < count($matchLines)) {
            $end = $matchLines[$offset + $limit];

            return implode("\n", array_slice($lines, $start, $end - $start));
        }

        return implode("\n", array_slice($lines, $start));
    }


    /**
     * @param array<string, mixed> $arguments
     */
    public function isReadonly(string $name, array $arguments): bool
    {
        return true;
    }

    protected function process(AgentInterface $agent, ToolCall $call): ProcessInterface
    {
        return $this->maker->make(ProcessInterface::class, [
            'agent' => $agent,
            'call' => $call,
            'spill' => false,
        ]);
    }

}
