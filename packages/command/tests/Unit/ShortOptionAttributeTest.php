<?php

declare(strict_types=1);

namespace Switon\Command\Tests\Unit;

use Switon\Command\Attribute\ShortOption;
use Switon\Command\CommandInspector;
use Switon\Command\Tests\TestCase;

class ShortOptionAttributeTest extends TestCase
{
    public function testShortOptionStoresLetter(): void
    {
        $this->assertSame('x', (new ShortOption('x'))->letter);
    }

    public function testShortOptionIsBoundOnActionParameters(): void
    {
        $inspector = $this->make(CommandInspector::class);
        $params = $inspector->getActionParameters(ShortOptionFixture::class, 'run');

        $this->assertArrayHasKey('value', $params);
        $this->assertSame('string', $params['value']['type']);
    }
}

class ShortOptionFixture
{
    public function runAction(
        #[ShortOption('x')]
        string $value
    ): void {
    }
}
