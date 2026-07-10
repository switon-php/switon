<?php

declare(strict_types=1);

namespace Switon\Inspector\Tests\Unit\Collectors;

use Switon\ComposerExtra\ComposerExtraInterface;
use Switon\Inspector\Collector\ProviderCollector;
use Switon\Inspector\Tests\TestCase;

class ProviderCollectorTest extends TestCase
{
    public function testCollectMapsProviderFqcnToPackageName(): void
    {
        $extra = $this->createStub(ComposerExtraInterface::class);
        $extra->method('all')->willReturn([
            'vendor/pkg-a' => [
                'switon' => [
                    'providers' => ['Provider\\A', 'Provider\\B'],
                ],
            ],
            'vendor/pkg-b' => [
                'switon' => [],
            ],
            'vendor/pkg-c' => [],
        ]);

        $this->container->replace(ComposerExtraInterface::class, $extra);
        $collector = $this->container->make(ProviderCollector::class);

        $map = $collector->collect();

        $this->assertSame([
            'Provider\\A' => 'vendor/pkg-a',
            'Provider\\B' => 'vendor/pkg-a',
        ], $map);
    }

    public function testJsonSerializeReturnsEmptyArray(): void
    {
        $collector = new ProviderCollector();
        $this->assertSame([], $collector->jsonSerialize());
    }

    public function testBootIsNoOp(): void
    {
        (new ProviderCollector())->boot();
        $this->assertTrue(true);
    }
}
