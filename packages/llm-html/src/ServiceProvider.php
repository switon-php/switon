<?php

declare(strict_types=1);

namespace Switon\LlmHtml;

use Switon\Core\ContainerInterface;
use Switon\Core\ServiceProviderInterface;

/**
 * Package startup hook. Interface→impl mappings follow Switon naming convention
 * ({@see LlmHtmlInterface} → {@see LlmHtml}, etc.) and need no explicit set().
 */
class ServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
    }

    public function boot(): void
    {
    }
}
