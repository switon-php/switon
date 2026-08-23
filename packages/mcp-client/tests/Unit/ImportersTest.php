<?php

declare(strict_types=1);

namespace Switon\McpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\McpClient\Exception;
use Switon\McpClient\Importer\McpServersJsonImporter;
use Switon\McpClient\Importers;
use Switon\McpClient\Tests\TestCase;

use function bin2hex;
use function file_put_contents;
use function json_encode;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(Importers::class)]
class ImportersTest extends TestCase
{
    public function testSetHasAndAllUsePathKeys(): void
    {
        $importers = $this->make(Importers::class);
        $importers->set('~/.cursor/mcp.json', McpServersJsonImporter::class);

        $this->assertTrue($importers->has('~/.cursor/mcp.json'));
        $this->assertSame(
            ['~/.cursor/mcp.json' => McpServersJsonImporter::class],
            $importers->all(),
        );
    }

    public function testImportDispatchesByResolvedPath(): void
    {
        $file = sys_get_temp_dir() . '/switon-mcp-import-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($file, json_encode([
            'mcpServers' => [
                'docs' => ['command' => 'echo', 'args' => ['hi']],
                'hub' => ['url' => 'https://example.com/mcp', 'headers' => ['A' => '1']],
                'off' => ['command' => 'x', 'disabled' => true],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $importers = $this->make(Importers::class);
            $importers->set($file, McpServersJsonImporter::class);

            $entries = $importers->import($file);
            $this->assertSame(['command' => 'echo', 'args' => ['hi']], $entries['docs']);
            $this->assertSame(['url' => 'https://example.com/mcp', 'headers' => ['A' => '1']], $entries['hub']);
            $this->assertArrayNotHasKey('off', $entries);
        } finally {
            @unlink($file);
        }
    }

    public function testImportRaisesWhenPathIsUnknown(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No MCP importer for');

        $this->make(Importers::class)->import('~/.missing/mcp.json');
    }

    public function testSetRejectsNonImporterClass(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('must implement ImporterInterface');

        $this->make(Importers::class)->set('~/.x.json', \stdClass::class);
    }
}
