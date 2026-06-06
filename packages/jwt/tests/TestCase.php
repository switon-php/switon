<?php

declare(strict_types=1);

namespace Switon\Jwt\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Switon\Core\ClockInterface;
use Switon\Crypto\CipherInterface;
use Switon\Jwt\JwtInterface;
use Switon\Testing\Container;
use Switon\Testing\MockClock;

/**
 * Base test case for JWT tests.
 */
abstract class TestCase extends BaseTestCase
{
    protected Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        // Use pre-configured test container (ClockInterface is already registered)
        $this->container = new Container();

        // Register dependencies
        $this->setUpContainer();
    }

    protected function setUpContainer(): void
    {
        // Override in subclasses to set up specific dependencies
    }

    protected function createMockCipher(): CipherInterface
    {
        // Use createStub() since we only need to configure method return values, not verify calls
        $cipher = $this->createStub(CipherInterface::class);
        $cipher->method('getDerivedKey')
            ->willReturnCallback(function (string $type, int $bits): string {
                return substr(hash('sha512', 'test-master-key:' . $type . ':' . $bits), 0, intdiv($bits, 4));
            });
        return $cipher;
    }

    protected function createJwt(
        ?string         $key = null,
        string          $alg = 'HS256',
        int             $leeway = 0,
        ?ClockInterface $clock = null
    ): JwtInterface {
        // Remove existing services if already resolved to avoid ServiceAlreadyResolvedException
        $this->container->remove(CipherInterface::class);

        $cipher = $this->createMockCipher();
        $this->container->set(CipherInterface::class, $cipher);

        // Use provided clock or create new mock clock for testing
        if ($clock === null) {
            $clock = new MockClock(time());
        }
        $this->container->set(ClockInterface::class, $clock);

        // JwtInterface -> Jwt is auto-mapped by container convention.
        // Build directly through make() with explicit test parameters.
        return $this->container->make(JwtInterface::class, [
            'key' => $key,
            'alg' => $alg,
            'leeway' => $leeway,
            'clock' => $clock,
        ]);
    }

}
