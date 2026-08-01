<?php

declare(strict_types=1);

namespace Switon\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Mcp\ArgumentBinderInterface;
use Switon\Mcp\Exception\InvalidParamsException;
use Switon\Mcp\Tests\Fixtures\DisabledTool;
use Switon\Mcp\Tests\Fixtures\EchoTool;
use Switon\Mcp\Tests\Fixtures\OrderService;
use Switon\Mcp\Tests\Fixtures\OrderStatusTool;
use Switon\Mcp\Tests\Fixtures\PingTool;
use Switon\Mcp\Tests\TestCase;
use Switon\Mcp\Tools;

#[CoversClass(Tools::class)]
class ToolsTest extends TestCase
{
    public function testAllReturnsConfiguredTools(): void
    {
        $this->container->set(EchoTool::class, new EchoTool());

        $tools = $this->make(Tools::class, [
            'tools' => ['echo' => EchoTool::class],
        ]);

        $tool = $tools->all()['echo'];
        $this->assertInstanceOf(EchoTool::class, $tool);
        $schema = $tool->schema('echo');
        $this->assertNotNull($schema);
        $this->assertSame('Echo path argument.', $schema->definition['description']);
        $this->assertSame(['path'], $schema->definition['inputSchema']['required']);
        $this->assertSame(
            ['name' => 'echo', 'path' => '/demo'],
            $tool->call('echo', ['path' => '/demo']),
        );
    }

    public function testAllReturnsOrderStatusTool(): void
    {
        $this->container->set(OrderService::class, new OrderService());
        $this->container->set(OrderStatusTool::class, new OrderStatusTool($this->container->get(OrderService::class)));

        $tools = $this->make(Tools::class, [
            'tools' => ['order_get_status' => OrderStatusTool::class],
        ]);

        $tool = $tools->all()['order_get_status'];
        $schema = $tool->schema('order_get_status');
        $this->assertNotNull($schema);
        $this->assertSame('Look up order status by orderId', $schema->definition['description']);
        $this->assertSame('integer', $schema->definition['inputSchema']['properties']['orderId']['type']);
        $this->assertSame(
            ['orderId' => 42, 'status' => 'paid'],
            $tool->call('order_get_status', ['orderId' => 42]),
        );
    }

    public function testAllSkipsToolsWithNullSchema(): void
    {
        $this->container->set(DisabledTool::class, new DisabledTool());

        $tools = $this->make(Tools::class, [
            'tools' => ['gone' => DisabledTool::class],
        ]);

        $this->assertArrayNotHasKey('gone', $tools->all());
    }

    public function testGetReturnsPublishedToolOnly(): void
    {
        $this->container->set(EchoTool::class, new EchoTool());
        $this->container->set(DisabledTool::class, new DisabledTool());

        $tools = $this->make(Tools::class, [
            'tools' => [
                'echo' => EchoTool::class,
                'gone' => DisabledTool::class,
            ],
        ]);

        $this->assertInstanceOf(EchoTool::class, $tools->get('echo'));
        $this->assertNull($tools->get('gone'));
        $this->assertNull($tools->get('missing'));
        $this->assertTrue($tools->has('gone'));
    }

    public function testArgumentBinderRejectsMissingRequired(): void
    {
        $this->container->set(EchoTool::class, new EchoTool());

        $tools = $this->make(Tools::class, [
            'tools' => ['echo' => EchoTool::class],
        ]);

        $schema = $tools->all()['echo']->schema('echo');
        $this->assertNotNull($schema);

        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessage('path is required');

        $this->container->get(ArgumentBinderInterface::class)->bind($schema, []);
    }

    public function testAddInsertsTool(): void
    {
        $tools = $this->make(Tools::class);
        $tool = new PingTool();

        $this->assertFalse($tools->has('demo_ping'));
        $tools->add('demo_ping', $tool);

        $this->assertTrue($tools->has('demo_ping'));
        $this->assertSame($tool, $tools->all()['demo_ping']);
    }

    public function testAddOverwritesExistingTool(): void
    {
        $tools = $this->make(Tools::class);
        $original = new PingTool();
        $replacement = new EchoTool();

        $tools->add('demo_ping', $original);
        $tools->add('demo_ping', $replacement);

        $this->assertSame($replacement, $tools->all()['demo_ping']);
    }
}
