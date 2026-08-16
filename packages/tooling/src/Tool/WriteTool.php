<?php

declare(strict_types=1);

namespace Switon\Tooling\Tool;

use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Path;
use Switon\Lint\LinterInterface;
use Switon\Tooling\AbstractTool;

/** Tool `write`. */
class WriteTool extends AbstractTool
{
    #[Autowired] protected LinterInterface $linter;

    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Write a file to the local filesystem. On success, may include a `lint` report for the path when a driver exists.',
            properties: [
                'path' => [
                    'type' => 'string',
                    'description' => 'Absolute path.',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'File content.',
                ],
            ],
            required: ['path', 'content'],
        );
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        $path = Path::absolute($arguments['path']);
        $content = $arguments['content'];
        $this->filesystem->write($path, $content);

        // Path is already on the call args / tool line — do not echo it in the result.
        return $this->linter->attach([
            'bytes' => strlen($content),
        ], $path, fn (): bool => $agent->isCancelled() || $call->cancelled);
    }


}
