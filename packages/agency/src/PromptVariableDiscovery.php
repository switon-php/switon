<?php

declare(strict_types=1);

namespace Switon\Agency;

use Switon\Ai\PromptRendererInterface;
use Switon\Ai\PromptVariablesInterface;
use Switon\ComposerExtra\ComposerExtraInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ContainerInterface;

/**
 * Registers package prompt variables from Composer extra onto {@see PromptRendererInterface}.
 *
 * Guidance: list classes in `extra.switon.prompt.variables`; each must implement {@see PromptVariablesInterface}.
 *
 * Road-signs:
 * - packages: {@see ComposerExtraInterface::collect()} `switon.prompt.variables`
 * - register {@see PromptRendererInterface::registerVariables()}
 *
 * @see \Switon\Agency\PromptVariableDiscoveryInterface
 * @see \Switon\ComposerExtra\ComposerExtraInterface
 * @see \Switon\Agency\ServiceProvider
 */
class PromptVariableDiscovery implements PromptVariableDiscoveryInterface
{
    #[Autowired] protected ComposerExtraInterface $composerExtra;
    #[Autowired] protected ContainerInterface $container;
    #[Autowired] protected PromptRendererInterface $promptRenderer;

    public function discover(): void
    {
        foreach ($this->composerExtra->collect('switon.prompt.variables') as $class) {
            /** @var PromptVariablesInterface $variables */
            $variables = $this->container->get($class);
            $this->promptRenderer->registerVariables($variables);
        }
    }
}
