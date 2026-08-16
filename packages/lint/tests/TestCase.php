<?php

declare(strict_types=1);

namespace Switon\Lint\Tests;

use Switon\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (extension_loaded('swoole')) {
            \Swoole\Runtime::setHookFlags(0);
            \Switon\Core\Runtime::setCoroutineEnabled(false);
        }
    }
}
