<?php

declare(strict_types=1);

namespace Switon\Tooling\Tool;

use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Switon\Core\Exception\RuntimeException;
use Switon\Core\Path;
use Switon\Tooling\AbstractTool;

/** Tool `rm`. File delete via `paths` (one or many absolute paths). */
class RmTool extends AbstractTool
{
    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Delete file(s). Prefer this over shell rm. Pass one or more absolute paths.',
            properties: [
                'paths' => [
                    'type' => 'array',
                    'description' => 'Absolute file paths (min 1).',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'string',
                        'minLength' => 1,
                    ],
                ],
            ],
            required: ['paths'],
        );
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        /** @var list<array{path: string, error: string}> $failed */
        $failed = [];
        $seen = [];
        foreach ($arguments['paths'] as $path) {
            $path = Path::absolute($path);
            if (isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;

            if (!$this->filesystem->isFile($path)) {
                $failed[] = ['path' => $path, 'error' => 'File not found'];
                continue;
            }
            try {
                $this->filesystem->delete($path);
            } catch (RuntimeException) {
                $failed[] = ['path' => $path, 'error' => 'Failed to delete file'];
            }
        }

        // Call args already name the paths. Successes stay out; only real failures come back.
        return $failed === [] ? 'success' : ['failed' => $failed];
    }
}
