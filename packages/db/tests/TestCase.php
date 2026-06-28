<?php

declare(strict_types=1);

namespace Switon\Db\Tests;

use Switon\Core\App;
use Switon\Db\ClientInterface;
use Switon\Db\Tests\Fixtures\StubDbClient;
use Switon\Di\Container as DiContainer;
use Switon\Testing\TestCase as BaseTestCase;
use Throwable;

/**
 * Base test case for DB tests.
 */
abstract class TestCase extends BaseTestCase
{
    protected ClientInterface $poolOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->poolOwner = new StubDbClient();

        // Set container in App for make() function
        App::setContainer($this->container);
    }

    protected function tearDown(): void
    {
        if (isset($this->client) && $this->client instanceof \Switon\Db\Client) {
            try {
                $this->client->close();
            } catch (Throwable $e) {
                // Ignore cleanup errors during test teardown
            }
        }

        // Clear container reference
        App::setContainer(new DiContainer());

        // Clear any test state to ensure test isolation
        // PHP's garbage collector will handle cleanup of objects and their internal state
        parent::tearDown();
    }
}
