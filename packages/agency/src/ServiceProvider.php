<?php

declare(strict_types=1);

namespace Switon\Agency;

use Switon\Agency\Toolkit\AsToolToolkit;
use Switon\Ai\InstructorInterface;
use Switon\Ai\PromptRendererInterface;
use Switon\Tooling\ToolsInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Attribute\ResourceAlias;
use Switon\Core\ContainerInterface;
use Switon\Core\ServerUuidInterface;
use Switon\Core\ServiceProviderInterface;

/**
 * Cross-package bindings, then boot mounts the as-tool toolkit.
 *
 * Road-signs: {@see InstructorInterface}→{@see Instructor}
 *
 * Guidance: Thread storage via `@threads` ({@see \Switon\Thread\ServiceProvider}).
 *
 * @see \Switon\Tooling\ToolsInterface
 */
#[ResourceAlias(alias: '@agency')]
class ServiceProvider implements ServiceProviderInterface
{
    #[Autowired] protected ToolsInterface $tools;
    #[Autowired] protected AsToolToolkit $asToolToolkit;
    #[Autowired] protected ServerUuidInterface $serverUuid;
    #[Autowired] protected ProfileDiscoveryInterface $profileDiscovery;
    #[Autowired] protected ExtensionsInterface $agentExtensions;
    #[Autowired] protected PromptVariableDiscoveryInterface $promptVariables;

    public function register(ContainerInterface $container): void
    {
        $container->set(InstructorInterface::class, Instructor::class);
        $container->set(PromptRendererInterface::class, PromptRenderer::class);
    }

    public function boot(): void
    {
        $this->tools->addToolkit('agent', $this->asToolToolkit);
        $this->profileDiscovery->discover();
        $this->tools->reload();
        $this->agentExtensions->discover();
        $this->promptVariables->discover();
        $this->serverUuid->get();
    }
}
