<?php

declare(strict_types=1);

namespace Switon\Langfuse;

use Switon\Core\Attribute\Autowired;
use Switon\Core\ContainerInterface;
use Switon\Core\ServiceProviderInterface;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Langfuse\Observability\LlmListener;

use function trim;

/**
 * Registers LLM lifecycle listener when Langfuse is configured.
 *
 * Set {@see ClientInterface::baseUrl()} in app config to enable; leave empty to disable.
 */
class ServiceProvider implements ServiceProviderInterface
{
    #[Autowired] protected ListenerProviderInterface $listenerProvider;
    #[Autowired] protected ClientInterface $client;

    public function register(ContainerInterface $container): void
    {
    }

    public function boot(): void
    {
        if (trim($this->client->baseUrl()) !== '') {
            $this->listenerProvider->register(LlmListener::class);
        }
    }
}
