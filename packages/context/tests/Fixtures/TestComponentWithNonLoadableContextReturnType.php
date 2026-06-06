<?php

declare(strict_types=1);

namespace Switon\Context\Tests\Fixtures;

use Switon\Core\ContextAware;
use RuntimeException;

/**
 * Declares a return type that is not a loadable class or interface (symbol is never defined).
 */
class TestComponentWithNonLoadableContextReturnType implements ContextAware
{
    public function getContext(): \Switon\Context\Tests\Fixtures\_ContextTypeIntentionallyUndefinedForCoverage
    {
        throw new RuntimeException('Body is not used by ContextManager reflection-only paths.');
    }
}
