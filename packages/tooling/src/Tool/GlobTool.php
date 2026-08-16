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
use Switon\Tooling\Exception;
use Switon\Tooling\ProcessInterface;

use function is_string;
use function json_encode;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Tool `glob`.
 *
 * Guidance: `rg` must be on PATH. Set `$rg` only to pin another binary.
 */
class GlobTool extends AbstractTool
{
    #[Autowired] protected MakerInterface $maker;

    #[Autowired] protected int $maxResults = 500;
    /** ripgrep executable (name or absolute path). Default uses process PATH. */
    #[Autowired] protected string $rg = 'rg';

    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Find files matching a glob pattern. Paths in the result are relative to target_directory.',
            properties: [
                'glob_pattern' => [
                    'type' => 'string',
                    'description' => 'e.g. *.php, **/AGENTS.md, or **/test_*.ts.',
                ],
                'target_directory' => [
                    'type' => 'string',
                    'description' => 'Directory to search (result paths are relative to this). Absolute path. Omit for the workspace root.',
                ],
                'head_limit' => [
                    'type' => 'integer',
                    'description' => 'Max paths to collect. Stops when reached.',
                    'minimum' => 1,
                    'default' => $this->maxResults,
                ],
            ],
            required: ['glob_pattern'],
        );
    }


    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        $globPattern = $arguments['glob_pattern'];
        $targetDirectory = Path::absolute($arguments['target_directory'] ?? $agent->workspace());
        $headLimit = (int)($arguments['head_limit'] ?? $this->maxResults);

        $process = $this->process($agent, $call);
        $collection = $process->start(
            [$this->rg,
            '--files',
            '--glob',
            $globPattern,
            $targetDirectory],
            $agent->workspace(),
            $process->timeout(),
        )->lines(new ToolResultDelta($this, $call->id, $call->name, $agent, ''), $headLimit);

        $timeout = ($collection['timeout'] ?? false) === true;
        $truncated = ($collection['truncated'] ?? false) === true;
        $exitCode = $collection['exit'];

        // rg: 0 = hits, 1 = no matches, ≥2 = error. Exit is not returned — empty
        // paths already means no match; real failures raise below.
        if (!$timeout && !$truncated && $exitCode > 1) {
            $stderr = trim($collection['stderr'] ?? '') ?: 'unknown error';
            Exception::raise('ripgrep failed: {stderr}', ['stderr' => $stderr]);
        }

        $paths = [];
        foreach ($collection['lines'] as $line) {
            $paths[] = $this->relativePath($line, $targetDirectory);
        }
        sort($paths);

        /** @var array{paths: list<string>, truncated?: bool, timeout?: bool, stderr?: string} $result */
        $result = [
            'paths' => $paths,
        ];

        if ($truncated) {
            $result['truncated'] = true;
        }

        if ($timeout) {
            $result['timeout'] = true;
        }

        if (($collection['stderr'] ?? '') !== '') {
            $result['stderr'] = $collection['stderr'];
        }

        return $this->spillResult($call->id, $agent, $result);
    }

    /**
     * @param array{paths: list<string>, truncated?: bool, timeout?: bool, stderr?: string} $result
     *
     * @return array{paths: list<string>, truncated?: bool, timeout?: bool, stderr?: string}|string
     */
    protected function spillResult(string $callId, AgentInterface $agent, array $result): array|string
    {
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return $result;
        }

        $out = $this->spillField($json, $callId, $agent);
        if ($out !== $json) {
            return $out;
        }

        return $result;
    }

    /** Path of `$line` relative to `$root`. `$root` itself is `.`. A line outside `$root` stays unchanged. */
    protected function relativePath(string $line, string $root): string
    {
        if ($root !== '/' && $root !== '\\') {
            $root = rtrim($root, '/\\');
        }

        if ($line === $root) {
            return '.';
        }

        foreach (['/', '\\'] as $separator) {
            if ($root === $separator) {
                if (str_starts_with($line, $separator)) {
                    return substr($line, 1);
                }
                continue;
            }

            $prefix = $root . $separator;
            if (str_starts_with($line, $prefix)) {
                return substr($line, strlen($prefix));
            }
        }

        return $line;
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
        ]);
    }

}
