<?php

declare(strict_types=1);

namespace Switon\Tooling\Tool;

use Switon\Ai\AgentInterface;
use Switon\Ai\Event\ToolResultDelta;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Exception\FileNotFoundException;
use Switon\Core\MakerInterface;
use Switon\Core\Path;
use Switon\Core\PathAliasInterface;
use Switon\Tooling\AbstractTool;
use Switon\Tooling\Exception;
use Switon\Tooling\ProcessInterface;

use function array_slice;
use function count;
use function dirname;
use function explode;
use function implode;
use function is_int;
use function ltrim;
use function max;
use function preg_match;
use function preg_split;
use function rtrim;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Tool `recall`. Text under the thread directory.
 *
 * Guidance: media files belong on `media`, not here.
 */
class RecallTool extends AbstractTool
{
    #[Autowired] protected MakerInterface $maker;
    #[Autowired] protected PathAliasInterface $pathAlias;

    /** Relative path used when `path` is omitted. */
    #[Autowired] protected string $transcript = 'transcript.md';

    /** ripgrep executable (name or absolute path). Default uses process PATH. */
    #[Autowired] protected string $rg = 'rg';

    /** Wall-clock cap in seconds for a pattern search. */
    #[Autowired] protected int $timeout = 30;

    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Read a file in this thread directory. '
            . '`file` is relative to that directory, or @thread/…. Any file there is valid. '
            . 'Default file ' . $this->transcript . '. '
            . 'Optional pattern greps the file (rg). '
            . 'For images/video use media.',
            properties: [
                'file' => [
                    'type' => 'string',
                    'description' => 'Thread-relative, or @thread/….',
                    'default' => $this->transcript,
                ],
                'pattern' => [
                    'type' => 'string',
                ],
                '-B' => [
                    'type' => 'integer',
                ],
                '-A' => [
                    'type' => 'integer',
                ],
                'head_limit' => [
                    'type' => 'integer',
                    'default' => 200,
                ],
                'offset' => [
                    'type' => 'integer',
                ],
                'multiline' => [
                    'type' => 'boolean',
                ],
                '-i' => [
                    'type' => 'boolean',
                ],
            ],
        );
    }


    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        $file = $arguments['file'];
        if ($file === '') {
            $file = $this->transcript;
        }
        if (str_starts_with($file, '@thread/')) {
            $file = ltrim(substr($file, strlen('@thread/')), '/');
        }

        $datadir = $agent->thread()->datadir;
        if ($datadir === null) {
            Exception::raise('File not found: "{file}".', ['file' => $file]);
        }

        $file = Path::normalize($file);
        if (!Path::isRelative($file)) {
            Exception::raise('File "{file}" must be a relative path.', ['file' => $file]);
        }

        $root = $this->pathAlias->resolve(rtrim($datadir, '/'));
        $absolute = Path::resolve($root, $file);
        if ($absolute === null || !$this->filesystem->isFile($absolute)) {
            Exception::raise('File not found: "{file}".', ['file' => $file]);
        }
        $pattern = $arguments['pattern'] ?? null;

        if ($pattern === null || $pattern === '') {
            try {
                $bytes = $this->filesystem->read($absolute);
            } catch (FileNotFoundException) {
                Exception::raise('File not found: "{file}".', ['file' => $file]);
            }

            return $this->spillField($bytes, $call->id, $agent);
        }

        $found = $this->search($absolute, $pattern, $arguments, $call, $agent);

        return [
            'content' => $this->numbered($found['stdout'], $absolute),
            'matches' => $found['exit'] === 0,
        ];
    }

    /**
     * `rg` on this one file. `head_limit` is the match cap; `offset` drops earlier matches.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{exit: int, stdout: string}
     */
    protected function search(
        string $file,
        string $pattern,
        array $arguments,
        ToolCall $call,
        AgentInterface $agent,
    ): array {
        $offset = isset($arguments['offset']) && is_int($arguments['offset']) ? max(0, $arguments['offset']) : 0;
        $headLimit = 200;
        if (isset($arguments['head_limit']) && is_int($arguments['head_limit'])) {
            $headLimit = max(0, $arguments['head_limit']);
        }
        $command = [
            $this->rg,
            '--color=never',
            '--line-number',
            '--no-heading',
            '--max-count=' . ($offset + $headLimit),
        ];
        if (isset($arguments['-A']) && is_int($arguments['-A']) && $arguments['-A'] !== 0) {
            $command[] = '--after-context=' . $arguments['-A'];
        }
        if (isset($arguments['-B']) && is_int($arguments['-B']) && $arguments['-B'] !== 0) {
            $command[] = '--before-context=' . $arguments['-B'];
        }
        if (($arguments['multiline'] ?? false) === true) {
            $command[] = '-U';
            $command[] = '--multiline-dotall';
        }
        if (($arguments['-i'] ?? false) === true) {
            $command[] = '--ignore-case';
        }
        $command[] = $pattern;
        $command[] = $file;

        /** @var ProcessInterface $process */
        $process = $this->maker->make(ProcessInterface::class, [
            'agent' => $agent,
            'call' => $call,
            'spill' => false,
        ]);
        $result = $process->start($command, dirname($file), max(1, $this->timeout))
            ->output(new ToolResultDelta($this, $call->id, $call->name, $agent, ''));

        return [
            'exit' => $result['exit'],
            'stdout' => $offset === 0 ? $result['stdout'] : $this->withoutSkipped($result['stdout'], $offset),
        ];
    }

    /** Drop the first `$offset` match lines. Context in front of the kept match goes with it. */
    protected function withoutSkipped(string $stdout, int $offset): string
    {
        $lines = explode("\n", $stdout);
        $matchLines = [];
        foreach ($lines as $index => $line) {
            if ($line !== '' && preg_match('/^(?:.*?:)?\d+:/', $line) === 1) {
                $matchLines[] = $index;
            }
        }
        if ($offset >= count($matchLines)) {
            return '';
        }

        return implode("\n", array_slice($lines, $matchLines[$offset]));
    }

    protected function numbered(string $stdout, string $file): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $stdout) ?: [];
        $out = [];
        foreach ($lines as $line) {
            if ($line === '' || $line === '--') {
                continue;
            }
            $rest = $line;
            if (str_starts_with($line, $file . ':') || str_starts_with($line, $file . '-')) {
                $rest = substr($line, strlen($file) + 1);
            }
            if (preg_match('/^(\d+)[:\-](.*)$/', $rest, $matches) !== 1) {
                continue;
            }
            $out[] = 'L' . $matches[1] . ': ' . $matches[2];
        }

        return implode("\n", $out);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function isReadonly(string $name, array $arguments): bool
    {
        return true;
    }

}
