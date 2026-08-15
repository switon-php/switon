<?php

declare(strict_types=1);

namespace Switon\Llm\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\LlmInterface;
use Switon\Llm\Llm;
use Switon\Llm\ServiceProvider;
use Switon\Llm\Tests\TestCase;

#[CoversClass(ServiceProvider::class)]
class ServiceProviderTest extends TestCase
{
    public function testBootBindsTheDefaultClient(): void
    {
        $this->assertSame(Llm::class, $this->container->getDefinition(LlmInterface::class));
    }
}
