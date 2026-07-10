<?php

declare(strict_types=1);

namespace Switon\Inspector\Tests\Unit\Collectors;

use Switon\Core\AppInterface;
use Switon\Inspector\Collector\AppCollector;
use Switon\Inspector\Tests\TestCase;

class AppCollectorTest extends TestCase
{
    public function testCollectReturnsAppMetadataSnapshot(): void
    {
        $this->container->set(AppInterface::class, [
            'id' => 'inspect-id',
            'name' => 'Inspect Name',
            'version' => '9.9.9',
            'env' => 'test',
            'debug' => true,
            'timezone' => 'Europe/Berlin',
        ]);

        $app = $this->container->get(AppInterface::class);
        $collector = $this->container->make(AppCollector::class, [
            'app' => $app,
        ]);

        $this->assertSame([
            'id' => 'inspect-id',
            'name' => 'Inspect Name',
            'version' => '9.9.9',
            'timezone' => 'Europe/Berlin',
            'env' => 'test',
            'debug' => true,
        ], $collector->collect());
    }

    public function testJsonSerializeReturnsEmptyArray(): void
    {
        $collector = new AppCollector();
        $this->assertSame([], $collector->jsonSerialize());
    }

    public function testBootIsNoOp(): void
    {
        (new AppCollector())->boot();
        $this->assertTrue(true);
    }
}
