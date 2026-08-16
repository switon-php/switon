<?php

declare(strict_types=1);

namespace Switon\Tooling\Tool;

use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Switon\Core\Path;
use Switon\Tooling\Exception;

/**
 * Tool `edits`. Several exact replacements. A top-level `path` fills items that omit it.
 *
 * Guidance: every match is checked before any write; one failure writes nothing.
 *
 * @see EditTool
 */
class EditsTool extends EditTool
{
    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Apply several text replacements. Prefer single `edit` when one change is enough. Optional top-level `path` is the default for edits[] items that omit path (typical same-file batch). Item path overrides when set. Same path may appear more than once; later items see earlier results. Any failed match writes nothing. On success, may include `lint` reports for touched files.',
            properties: [
                'path' => [
                    'type' => 'string',
                    'description' => 'Default absolute path for edits[] items that omit path.',
                ],
                'edits' => [
                    'type' => 'array',
                    'description' => 'Ordered replacements (min 1). Each item needs old + new; path per item or via top-level path.',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => [
                                'type' => 'string',
                                'description' => 'Absolute path. Omit to inherit the top-level path.',
                            ],
                            'old' => [
                                'type' => 'string',
                                'description' => 'Text to find (non-empty).',
                                'minLength' => 1,
                            ],
                            'new' => [
                                'type' => 'string',
                                'description' => 'Replacement text.',
                            ],
                            'all' => [
                                'type' => 'boolean',
                                'description' => 'Replace every occurrence in that step. Omit for first match only.',
                                'default' => false,
                            ],
                        ],
                        'required' => ['old', 'new'],
                    ],
                ],
            ],
            required: ['edits'],
        );
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        $raw = $arguments['edits'];
        $rootPath = null;
        if (isset($arguments['path'])) {
            $rootPath = Path::absolute($arguments['path']);
        }

        /** @var array<string, string> $originals path => disk content before this call */
        $originals = [];
        /** @var array<string, string> $buffers path => working content */
        $buffers = [];
        /** @var list<array{path: string, replacements: int, line: int}> $steps */
        $steps = [];

        foreach ($raw as $index => $item) {
            $path = $rootPath;
            if (isset($item['path'])) {
                $path = Path::absolute($item['path']);
            }
            if ($path === null) {
                Exception::raise('edits[{index}].path required (set item path or top-level path).', ['index' => $index]);
            }

            if (!isset($buffers[$path])) {
                if (!$this->filesystem->isFile($path)) {
                    Exception::raise('File not found (edits[{index}]).', ['index' => $index,]);
                }
                $content = $this->filesystem->read($path);
                $originals[$path] = $content;
                $buffers[$path] = $content;
            }

            try {
                $applied = $this->replaceInContent(
                    $buffers[$path],
                    $item['old'],
                    $item['new'],
                    $item['all'] ?? false,
                );
            } catch (Exception $e) {
                Exception::raise('edits[{index}]: {reason}', [
                    'index' => $index,
                    'reason' => $e->getMessage(),
                ]);
            }
            $buffers[$path] = $applied['content'];
            $steps[] = [
                'path' => $path,
                'replacements' => $applied['count'],
                'line' => $applied['line'],
            ];
        }

        foreach ($buffers as $path => $content) {
            if ($content === $originals[$path]) {
                continue;
            }
            $this->filesystem->write($path, $content);
        }

        $files = array_keys($buffers);

        return $this->linter->attach([
            'edits' => $steps,
            'files' => $files,
        ], $files, fn (): bool => $agent->isCancelled() || $call->cancelled);
    }
}
