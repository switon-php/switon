<?php

declare(strict_types=1);

namespace Switon\Tooling\Tool;

use Switon\Ai\AgentInterface;
use Switon\Ai\Part\MediaPart;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolResult;
use Switon\Ai\ToolSchema;
use Switon\Core\MimeTypes;
use Switon\Tooling\AbstractTool;
use Switon\Tooling\Exception;

use function is_array;

/**
 * Tool `media`. Returns a {@see MediaPart}; the provider reads the file when it encodes.
 *
 * Guidance: only the `extra` object is copied onto the part. Other unknown fields are ignored.
 */
class MediaTool extends AbstractTool
{
    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Attach a media ref into context so you can view or describe it.',
            properties: [
                'prompt' => [
                    'type' => 'string',
                    'description' => 'What to look for or answer about this media.',
                ],
                'ref' => [
                    'type' => 'string',
                    'description' => 'Media ref: path relative to the conversation datadir, absolute under OS temp (/tmp), or http(s) / mm_file URL.',
                ],
                'mime' => [
                    'type' => 'string',
                    'description' => 'MIME type (e.g. image/png, video/mp4).',
                ],
            ],
            required: ['prompt', 'ref'],
        );
    }


    public function call(AgentInterface $agent, ToolCall $call, array $arguments): ToolResult
    {
        // Do not trim args — use as given.
        $ref = $arguments['ref'];
        $mime = $arguments['mime'] ?? '';
        if ($mime === '') {
            $mime = MimeTypes::guess($ref) ?? '';
        }
        if ($mime === '') {
            Exception::raise('Unknown media type. Pass mime (e.g. image/png, video/mp4).');
        }

        $prompt = $arguments['prompt'];
        $extra = is_array($arguments['extra'] ?? null) ? $arguments['extra'] : [];

        return new ToolResult($prompt, parts: [
            MediaPart::of($ref, mime: $mime, extra: $extra),
        ]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function isReadonly(string $name, array $arguments): bool
    {
        return true;
    }

}
