<?php

declare(strict_types=1);

namespace Switon\McpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\ComposerExtra\ComposerExtraInterface;
use Switon\McpClient\Exception;
use Switon\McpClient\Importer\ImporterDiscovery;
use Switon\McpClient\Importer\McpServersJsonImporter;
use Switon\McpClient\ImportersInterface;
use Switon\McpClient\Tests\TestCase;

#[CoversClass(ImporterDiscovery::class)]
class ImporterDiscoveryTest extends TestCase
{
    public function testDiscoverRegistersPathClassesFromExtra(): void
    {
        $extra = $this->createStub(ComposerExtraInterface::class);
        $extra->method('collect')->willReturn([
            '~/.cursor/mcp.json' => McpServersJsonImporter::class,
        ]);

        $importers = $this->make(ImportersInterface::class);
        $this->make(ImporterDiscovery::class, [
            'composerExtra' => $extra,
            'importers' => $importers,
        ])->discover();

        $this->assertSame(
            ['~/.cursor/mcp.json' => McpServersJsonImporter::class],
            $importers->all(),
        );
    }

    public function testDiscoverOverwritesAlreadyRegisteredPath(): void
    {
        $extra = $this->createStub(ComposerExtraInterface::class);
        $extra->method('collect')->willReturn([
            '~/.cursor/mcp.json' => McpServersJsonImporter::class,
        ]);

        $importers = $this->make(ImportersInterface::class);
        $importers->set('~/.cursor/mcp.json', McpServersJsonImporter::class);

        $this->make(ImporterDiscovery::class, [
            'composerExtra' => $extra,
            'importers' => $importers,
        ])->discover();

        $this->assertSame(
            ['~/.cursor/mcp.json' => McpServersJsonImporter::class],
            $importers->all(),
        );
    }

    public function testDiscoverRaisesOnEmptyPath(): void
    {
        $extra = $this->createStub(ComposerExtraInterface::class);
        $extra->method('collect')->willReturn([
            '' => McpServersJsonImporter::class,
        ]);

        $this->expectException(Exception::class);
        $this->make(ImporterDiscovery::class, [
            'composerExtra' => $extra,
            'importers' => $this->make(ImportersInterface::class),
        ])->discover();
    }

    public function testDiscoverRaisesOnUnknownClass(): void
    {
        $extra = $this->createStub(ComposerExtraInterface::class);
        $extra->method('collect')->willReturn([
            '~/.bad' => 'Not\\A\\Class',
        ]);

        $this->expectException(Exception::class);
        $this->make(ImporterDiscovery::class, [
            'composerExtra' => $extra,
            'importers' => $this->make(ImportersInterface::class),
        ])->discover();
    }
}
