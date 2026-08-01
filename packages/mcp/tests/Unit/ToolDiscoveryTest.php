<?php

declare(strict_types=1);

namespace Switon\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\ClassScannerInterface;
use Switon\Mcp\AbstractTool;
use Switon\Mcp\Tests\Fixtures\DisabledTool;
use Switon\Mcp\Tests\Fixtures\EchoTool;
use Switon\Mcp\Tests\Fixtures\PingTool;
use Switon\Mcp\Tests\TestCase;
use Switon\Mcp\ToolDiscovery;
use Switon\Mcp\Tools;

#[CoversClass(ToolDiscovery::class)]
class ToolDiscoveryTest extends TestCase
{
    public function testDiscoverAddsScannedTools(): void
    {
        $this->container->set(EchoTool::class, new EchoTool());
        $this->container->set(PingTool::class, new PingTool());

        $scanner = $this->createStub(ClassScannerInterface::class);
        $scanner->method('scan')->willReturn([EchoTool::class, PingTool::class]);

        $tools = $this->make(Tools::class);
        $discovery = $this->make(ToolDiscovery::class, [
            'classScanner' => $scanner,
            'tools' => $tools,
            'files' => ['@fixtures/*Tool.php' => 'Switon\\Mcp\\Tests\\Fixtures\\*Tool'],
        ]);
        $discovery->discover();

        $this->assertArrayHasKey('echo', $tools->all());
        $this->assertArrayHasKey('ping', $tools->all());
        $this->assertInstanceOf(EchoTool::class, $tools->all()['echo']);
        $this->assertInstanceOf(PingTool::class, $tools->all()['ping']);
    }

    public function testDiscoverSkipsAbstractAndNullSchemaClasses(): void
    {
        $this->container->set(DisabledTool::class, new DisabledTool());

        $scanner = $this->createStub(ClassScannerInterface::class);
        $scanner->method('scan')->willReturn([AbstractTool::class, DisabledTool::class]);

        $tools = $this->make(Tools::class);
        $discovery = $this->make(ToolDiscovery::class, [
            'classScanner' => $scanner,
            'tools' => $tools,
        ]);
        $discovery->discover();

        $this->assertTrue($tools->has('disabled'));
        $this->assertArrayNotHasKey('disabled', $tools->all());
    }

    public function testDiscoverDoesNotOverwriteExplicitTools(): void
    {
        $explicit = new PingTool();
        $this->container->set(EchoTool::class, new EchoTool());

        $scanner = $this->createStub(ClassScannerInterface::class);
        $scanner->method('scan')->willReturn([EchoTool::class]);

        $tools = $this->make(Tools::class);
        $tools->add('echo', $explicit);

        $discovery = $this->make(ToolDiscovery::class, [
            'classScanner' => $scanner,
            'tools' => $tools,
        ]);
        $discovery->discover();

        $this->assertSame($explicit, $tools->all()['echo']);
    }

    public function testDiscoverNoOpWhenFilesEmpty(): void
    {
        $scanner = $this->createMock(ClassScannerInterface::class);
        $scanner->expects($this->never())->method('scan');

        $discovery = $this->make(ToolDiscovery::class, [
            'classScanner' => $scanner,
            'files' => ['@app/Mcp/Tool/*Tool.php' => null],
        ]);

        $discovery->discover();
    }
}
