<?php

declare(strict_types=1);

namespace Switon\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Mcp\ServerInfo;
use Switon\Mcp\Tests\TestCase;

#[CoversClass(ServerInfo::class)]
class ServerInfoTest extends TestCase
{
    public function testDefaults(): void
    {
        $info = $this->container->make(ServerInfo::class);

        $this->assertSame('2024-11-05', $info->getProtocolVersion());
        $this->assertSame('switon', $info->getName());
        $this->assertSame('1.0.0', $info->getVersion());
        $this->assertNull($info->getDescription());
    }

    public function testAutowiredDescription(): void
    {
        $info = $this->make(ServerInfo::class, [
            'description' => 'MCP demo server',
            'version' => '2.0.0',
        ]);

        $this->assertSame('MCP demo server', $info->getDescription());
        $this->assertSame('2.0.0', $info->getVersion());
    }
}
