<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Fixtures;

use Switon\Http\Attribute\CacheControl;

/**
 * Controller actions exercised through {@see \Switon\Invoking\InvokerInterface}.
 */
final class CacheControlActionFixture
{
    #[CacheControl(maxAge: 3600)]
    public function simpleMaxAge(): void
    {
    }

    #[CacheControl(maxAge: 1800, public: false)]
    public function privateCache(): void
    {
    }

    #[CacheControl(maxAge: 3600, sMaxAge: 7200)]
    public function withSMaxAge(): void
    {
    }

    #[CacheControl(maxAge: 3600, mustRevalidate: true)]
    public function withMustRevalidate(): void
    {
    }

    #[CacheControl(noCache: true)]
    public function noCache(): void
    {
    }

    #[CacheControl(noStore: true)]
    public function noStore(): void
    {
    }

    #[CacheControl(maxAge: 3600, public: true, noStore: true)]
    public function noStoreOverridesOtherSettings(): void
    {
    }

    #[CacheControl(maxAge: 1800, public: false, sMaxAge: 3600, mustRevalidate: true)]
    public function complexConfiguration(): void
    {
    }

    #[CacheControl]
    public function onlyPublicWithoutMaxAge(): void
    {
    }
}
