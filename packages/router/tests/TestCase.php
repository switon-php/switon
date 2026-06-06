<?php

declare(strict_types=1);

namespace Switon\Routing\Tests;

use Switon\Routing\PatternCompilerInterface;
use Switon\Routing\Router;
use Switon\Testing\TestCase as BaseTestCase;

/**
 * Base test case for router tests.
 *
 * Provides common functionality for all router tests, including Router initialization
 * with mocked dependencies via container.
 */
abstract class TestCase extends BaseTestCase
{
    protected Router $router;
    protected \Switon\Routing\Tests\Fixtures\MockRequest $request;
    protected \Switon\Routing\Tests\Fixtures\MockPatternCompiler $patternCompiler;
    protected \Switon\Routing\Tests\Fixtures\MockEventDispatcher $eventDispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock dependencies
        $this->request = new \Switon\Routing\Tests\Fixtures\MockRequest();
        $this->patternCompiler = new \Switon\Routing\Tests\Fixtures\MockPatternCompiler();
        $this->eventDispatcher = new \Switon\Routing\Tests\Fixtures\MockEventDispatcher();

        // Register mocks in container
        $this->container->replace(PatternCompilerInterface::class, $this->patternCompiler);
        $this->container->replace(\Psr\EventDispatcher\EventDispatcherInterface::class, $this->eventDispatcher);

        // Create Router instance via container - dependencies will be autowired
        $this->router = $this->make(Router::class);
    }
}
