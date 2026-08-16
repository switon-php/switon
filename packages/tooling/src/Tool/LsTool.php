<?php

declare(strict_types=1);

namespace Switon\Tooling\Tool;

use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Switon\Core\Exception\RuntimeException;
use Switon\Core\Path;
use Switon\Tooling\AbstractTool;
use Switon\Tooling\Exception;

use function is_string;
use function json_encode;
use function max;
use function strlen;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/** Tool `ls`. */
class LsTool extends AbstractTool
{
    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'List a directory.',
            properties: [
                'path' => [
                    'type' => 'string',
                    'description' => 'Absolute path. Omit for the workspace root.',
                ],
            ],
            required: [],
        );
    }


    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        $callId = $call->id;
        $path = Path::absolute($arguments['path'] ?? $agent->workspace());
        if (!$this->filesystem->exists($path)) {
            Exception::raise('Path not found.');
        }

        if (!$this->filesystem->isDir($path)) {
            Exception::raise('Not a directory.');
        }

        $dirs = [];
        $files = [];
        try {
            $items = $this->filesystem->list($path);
        } catch (RuntimeException) {
            Exception::raise('Failed to list directory.');
        }

        foreach ($items as $entry) {
            $fullPath = $path . DIRECTORY_SEPARATOR . $entry;
            if ($this->filesystem->isDir($fullPath)) {
                $dirs[] = $entry;
                continue;
            }

            $file = ['name' => $entry];
            $size = $this->filesystem->size($fullPath);
            if ($size !== null) {
                $file['size'] = $size;
            }
            $files[] = $file;
        }

        sort($dirs, SORT_STRING);
        usort($files, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        // path is already in the tool args — result is only the listing.
        return $this->spillListing($callId, $agent, [
            'dirs' => $dirs,
            'files' => $files,
        ]);
    }

    /**
     * Spill `dirs` and `files` on their own. The envelope stays an object unless both fields
     * are under budget and the whole JSON still is not.
     *
     * @param array{dirs: list<string>, files: list<array{name: string, size?: int}>} $result
     *
     * @return array{dirs: list<string>|string, files: list<array{name: string, size?: int}>|string}|string
     */
    protected function spillListing(string $callId, AgentInterface $agent, array $result): array|string
    {
        $fieldSpilled = false;
        foreach (['dirs', 'files'] as $channel) {
            $value = $this->spillValue($callId, $agent, $result[$channel], $channel);
            if ($value !== $result[$channel]) {
                $fieldSpilled = true;
                $result[$channel] = $value;
            }
        }

        if ($fieldSpilled) {
            return $result;
        }

        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) <= max(1, $this->budget) * 1024) {
            return $result;
        }

        $out = $this->spillField($json, $callId, $agent);
        if ($out !== $json) {
            return $out;
        }

        return $result;
    }

    protected function spillValue(string $callId, AgentInterface $agent, mixed $value, string $channel): mixed
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return $value;
        }

        $out = $this->spillField($json, $callId, $agent, $channel);
        if ($out !== $json) {
            return $out;
        }

        return $value;
    }


    /**
     * @param array<string, mixed> $arguments
     */
    public function isReadonly(string $name, array $arguments): bool
    {
        return true;
    }

}
