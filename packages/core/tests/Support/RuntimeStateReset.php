<?php

declare(strict_types=1);

namespace Switon\Core\Tests\Support;

use ReflectionClass;
use Switon\Core\Runtime;

/**
 * Isolated reset for {@see Runtime} process-level static state in unit tests.
 */
final class RuntimeStateReset
{
    public static function reset(): void
    {
        Runtime::setCoroutineEnabled(false);

        $reflection = new ReflectionClass(Runtime::class);
        $rootProperty = $reflection->getProperty('root');
        $rootProperty->setValue(null, null);
    }
}
