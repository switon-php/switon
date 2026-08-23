<?php

declare(strict_types=1);

namespace Switon\McpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\McpClient\Importer\McpServersJsonImporter;
use Switon\McpClient\Tests\TestCase;

use function bin2hex;
use function file_put_contents;
use function json_encode;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

/**
 * Fixtures follow Cursor docs (`mcpServers` + command/url) and live Cursor/Claude/CodeBuddy shapes.
 */
#[CoversClass(McpServersJsonImporter::class)]
class McpServersJsonImporterTest extends TestCase
{
    public function testImportMatchesCursorStdioAndRemoteDocs(): void
    {
        // https://cursor.com/docs/mcp — CLI Server / Remote Server examples
        $file = $this->writeJson([
            'mcpServers' => [
                'node' => [
                    'command' => 'npx',
                    'args' => ['-y', 'mcp-server'],
                    'env' => ['API_KEY' => 'value'],
                ],
                'remote' => [
                    'url' => 'http://localhost:3000/mcp',
                    'headers' => ['API_KEY' => 'value'],
                ],
                'typed-http' => [
                    'type' => 'streamable-http',
                    'url' => 'http://127.0.0.1:9520/mcp',
                    'headers' => ['Authorization' => 'Bearer x'],
                ],
                'stdio-typed' => [
                    'type' => 'stdio',
                    'command' => 'uvx',
                    'args' => ['tool'],
                    'env' => ['GOGS_URL' => 'https://example.com'],
                ],
            ],
        ]);

        try {
            $out = $this->make(McpServersJsonImporter::class)->import($file);
            $this->assertSame([
                'node' => [
                    'command' => 'npx',
                    'args' => ['-y', 'mcp-server'],
                    'env' => ['API_KEY' => 'value'],
                ],
                'remote' => [
                    'url' => 'http://localhost:3000/mcp',
                    'headers' => ['API_KEY' => 'value'],
                ],
                'typed-http' => [
                    'url' => 'http://127.0.0.1:9520/mcp',
                    'headers' => ['Authorization' => 'Bearer x'],
                ],
                'stdio-typed' => [
                    'command' => 'uvx',
                    'args' => ['tool'],
                    'env' => ['GOGS_URL' => 'https://example.com'],
                ],
            ], $out);
        } finally {
            @unlink($file);
        }
    }

    public function testImportSkipsDisabledAndShapelessEntries(): void
    {
        $file = $this->writeJson([
            'mcpServers' => [
                'ok' => ['command' => 'echo'],
                'off' => ['command' => 'x', 'disabled' => true],
                'empty' => ['type' => 'http'],
                0 => ['command' => 'bad-id'],
            ],
            'other' => ['noise' => true],
        ]);

        try {
            $out = $this->make(McpServersJsonImporter::class)->import($file);
            $this->assertSame(['ok' => ['command' => 'echo']], $out);
        } finally {
            @unlink($file);
        }
    }

    public function testImportReadsVsCodeServersKey(): void
    {
        // https://code.visualstudio.com/docs/agents/reference/mcp-configuration — editor `servers` object
        $file = $this->writeJson([
            'servers' => [
                'playwright' => [
                    'type' => 'stdio',
                    'command' => 'npx',
                    'args' => ['-y', '@playwright/mcp@latest'],
                ],
                'learn' => [
                    'type' => 'http',
                    'url' => 'https://learn.microsoft.com/api/mcp',
                ],
            ],
        ]);

        try {
            $out = $this->make(McpServersJsonImporter::class)->import($file);
            $this->assertSame([
                'playwright' => [
                    'command' => 'npx',
                    'args' => ['-y', '@playwright/mcp@latest'],
                ],
                'learn' => [
                    'url' => 'https://learn.microsoft.com/api/mcp',
                ],
            ], $out);
        } finally {
            @unlink($file);
        }
    }

    public function testImportPrefersMcpServersWhenBothKeysPresent(): void
    {
        $file = $this->writeJson([
            'mcpServers' => ['a' => ['command' => 'from-mcpServers']],
            'servers' => ['a' => ['command' => 'from-servers']],
        ]);

        try {
            $out = $this->make(McpServersJsonImporter::class)->import($file);
            $this->assertSame(['a' => ['command' => 'from-mcpServers']], $out);
        } finally {
            @unlink($file);
        }
    }

    public function testImportReturnsEmptyWhenServerObjectMissingOrInvalid(): void
    {
        $importer = $this->make(McpServersJsonImporter::class);

        $noKey = $this->writeJson(['other' => ['a' => ['command' => 'x']]]);
        $badJson = sys_get_temp_dir() . '/switon-mcp-bad-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($badJson, '{not-json');

        try {
            $this->assertSame([], $importer->import($noKey));
            $this->assertSame([], $importer->import($badJson));
            $this->assertSame([], $importer->import('/tmp/switon-mcp-missing-' . bin2hex(random_bytes(4)) . '.json'));
        } finally {
            @unlink($noKey);
            @unlink($badJson);
        }
    }

    /** @param array<string, mixed> $data */
    protected function writeJson(array $data): string
    {
        $file = sys_get_temp_dir() . '/switon-mcp-json-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR));

        return $file;
    }
}
