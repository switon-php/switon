<?php

declare(strict_types=1);

namespace Switon\Binding\Tests\Unit\Attribute;

use Switon\Binding\Attribute\ResolvedBy;
use PHPUnit\Framework\TestCase;

class ResolvedByTest extends TestCase
{
    public function testAttributeStoresResolverFqcn(): void
    {
        $attr = new ResolvedBy('Switon\\Testing\\ExampleParameterResolver');

        self::assertSame('Switon\\Testing\\ExampleParameterResolver', $attr->getResolver());
    }
}
