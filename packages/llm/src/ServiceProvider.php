<?php

declare(strict_types=1);

namespace Switon\Llm;

use Switon\Ai\LlmInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ContainerInterface;
use Switon\Core\ServiceProviderInterface;

/** Registers LLM services and fills the catalog at boot. */
class ServiceProvider implements ServiceProviderInterface
{
    #[Autowired] protected LlmDiscoveryInterface $llmDiscovery;

    public function register(ContainerInterface $container): void
    {
        // Same-namespace DI mapping cannot see an interface that lives in ai.
        $container->set(LlmInterface::class, Llm::class);
    }

    public function boot(): void
    {
        $this->llmDiscovery->discover();
    }
}
