<?php

declare(strict_types=1);

namespace Switon\McpClient\Tool;

use Switon\Ai\AgentInterface;
use Switon\Ai\Part\MediaPart;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolResult;
use Switon\Ai\ToolSchema;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Exception\CreateDirectoryFailedException;
use Switon\Core\Exception\RuntimeException;
use Switon\Core\FilesystemInterface;
use Switon\Core\Lazy;
use Switon\Core\Strings;
use Switon\McpClient\ClientsInterface;
use Switon\McpClient\Exception;
use Switon\Tooling\AbstractTool;
use Switon\Tooling\ArgumentBinderInterface;

use function base64_decode;
use function dirname;
use function explode;
use function implode;
use function is_array;
use function is_string;
use function preg_match;
use function str_starts_with;
use function strlen;
use function strtolower;

/**
 * Tool `mcp_call`. Text spills under `tool-result/`. Images go to `tool-asset/`.
 *
 * Guidance: Text spill uses {@see AbstractTool}. Image files use `tool-asset/` in this class.
 * Nested `arguments` are bound against the remote tool schema before `tools/call`.
 */
class McpCallTool extends AbstractTool
{
    #[Autowired] protected ClientsInterface|Lazy $clients;
    #[Autowired] protected FilesystemInterface $filesystem;
    #[Autowired] protected ArgumentBinderInterface $argumentBinder;

    /** Inline / spill-start threshold in KiB. */
    #[Autowired] protected int $budget = 32;
    /** Preview edge length in bytes. */
    #[Autowired] protected int $keep = 1024;
    /** Decoded image byte cap. A larger block is not decoded or written. */
    #[Autowired] protected int $maxImageBytes = 8388608;

    public function schema(string $name): ?ToolSchema
    {
        unset($name);
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Call one tool on an MCP server. Arguments come from mcp_schema.',
            properties: [
                'server' => [
                    'type' => 'string',
                    'description' => 'MCP server id (mcp.yml key).',
                ],
                'tool' => [
                    'type' => 'string',
                    'description' => 'Remote tool name on that server.',
                ],
                'arguments' => [
                    'type' => 'object',
                    'description' => 'Tool arguments object.',
                ],
            ],
            required: ['server', 'tool'],
        );
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        $server = $arguments['server'];
        $tool = $arguments['tool'];
        /** @var array<string, mixed> $args */
        $args = $arguments['arguments'] ?? [];

        $client = $this->clients->get($server);
        $schema = $client->listTools(false)[$tool] ?? null;
        if (!$schema instanceof ToolSchema) {
            Exception::raise('Unknown MCP tool "{tool}" on "{server}".', ['tool' => $tool, 'server' => $server]);
        }
        $args = $this->argumentBinder->bind($schema, $args);

        $result = $client->callTool(
            $tool,
            $args,
            fn (): bool => $agent->isCancelled() || $call->cancelled,
        );

        if (is_string($result)) {
            return $this->spillField($result, $call->id, $agent);
        }
        if (!is_array($result)) {
            return $result;
        }
        if (($result['isError'] ?? false) === true) {
            $failed = new ToolResult($this->textFromBlocks($result));
            $failed->isError = true;

            return $failed;
        }

        $text = $this->textFromBlocks($result);
        $images = $this->storeImageParts($result, $call->id, $agent);
        if ($text !== '') {
            $text = $this->spillField($text, $call->id, $agent);
        }
        if ($images === []) {
            return $text !== '' ? $text : $result;
        }

        return new ToolResult($text, parts: $images);
    }

    public function isReadonly(string $name, array $arguments): bool
    {
        unset($name, $arguments);

        return false;
    }

    /** @param array<string, mixed> $result */
    protected function textFromBlocks(array $result): string
    {
        $content = $result['content'] ?? null;
        if (!is_array($content)) {
            return is_string($result['text'] ?? null) ? $result['text'] : '';
        }

        $parts = [];
        foreach ($content as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<MediaPart>
     */
    protected function storeImageParts(array $result, string $id, AgentInterface $agent): array
    {
        $content = $result['content'] ?? null;
        if (!is_array($content)) {
            return [];
        }

        $datadir = $agent->thread()->datadir;
        if ($datadir === null) {
            return [];
        }
        $toolCallId = $this->assetToolCallId($id);

        $parts = [];
        $index = 0;
        foreach ($content as $block) {
            if (!is_array($block) || ($block['type'] ?? '') !== 'image') {
                continue;
            }
            $mime = is_string($block['mimeType'] ?? null) ? $block['mimeType'] : '';
            $data = is_string($block['data'] ?? null) ? $block['data'] : '';
            if ($mime === '' || $data === '' || !str_starts_with($mime, 'image/')) {
                continue;
            }
            if (strlen($data) > intdiv($this->maxImageBytes, 3) * 4 + 4) {
                continue;
            }
            $bytes = base64_decode($data, true);
            if ($bytes === false || $bytes === '' || strlen($bytes) > $this->maxImageBytes) {
                continue;
            }

            $index++;
            $ref = $this->imageRef($toolCallId, $index, $mime);
            if ($ref === null) {
                continue;
            }
            $path = $datadir . '/' . $ref;
            $dir = dirname($path);
            try {
                $this->filesystem->mkdir($dir, 0777);
            } catch (CreateDirectoryFailedException) {
                continue;
            }
            try {
                $this->filesystem->write($path, $bytes);
            } catch (RuntimeException) {
                continue;
            }
            $parts[] = MediaPart::of($ref, mime: $mime);
        }

        return $parts;
    }

    protected function assetToolCallId(string $id): string
    {
        return Strings::fileName($id);
    }

    /** `image/jpeg` → `jpg`. Any other subtype must be 1–8 letters or digits. */
    protected function imageRef(string $toolCallId, int $index, string $mime): ?string
    {
        $ext = strtolower(explode('/', $mime, 2)[1] ?? '');
        $ext = explode('+', explode(';', $ext, 2)[0], 2)[0];
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }
        if (preg_match('/^[a-z0-9]{1,8}$/', $ext) !== 1) {
            return null;
        }

        return 'tool-asset/' . $toolCallId . '-' . $index . '.' . $ext;
    }
}
