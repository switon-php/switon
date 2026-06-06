<?php

declare(strict_types=1);

namespace Switon\Captcha\Tests;

use Switon\Captcha\CaptchaInterface;
use Switon\Captcha\StoreInterface;
use Switon\Captcha\Tests\Fixtures\MockStore;
use Switon\Core\PathAliasInterface;
use Switon\Core\RandomInterface;
use Switon\Testing\MockRandom;
use Switon\Testing\TestCase as BaseTestCase;

/**
 * Base test case for Captcha tests.
 */
abstract class TestCase extends BaseTestCase
{
    protected MockStore $mockStore;
    protected MockRandom $mockRandom;

    protected function setUpContainer(): void
    {
        parent::setUpContainer();

        // Create mock instances
        $this->mockStore = new MockStore();
        $this->mockRandom = new MockRandom(null, 12345); // Seeded for deterministic behavior

        // Register mocks
        $this->container->set(StoreInterface::class, $this->mockStore);
        $this->container->set(RandomInterface::class, $this->mockRandom);
    }

    /**
     * Creates a CAPTCHA instance for testing.
     *
     * @param array<string, mixed> $parameters Optional constructor parameters
     *
     * @return CaptchaInterface
     */
    protected function createCaptcha(array $parameters = []): CaptchaInterface
    {
        return $this->make(CaptchaInterface::class, $parameters);
    }

    protected function getFontPath(string $font = 'Vera.ttf'): string
    {
        /** @var PathAliasInterface $pathAlias */
        $pathAlias = $this->container->get(PathAliasInterface::class);

        return $pathAlias->resolve('@switon.captcha.resources/Fonts/' . $font);
    }
}
