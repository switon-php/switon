<?php

declare(strict_types=1);

namespace Switon\Tooling;

use Switon\Ai\ToolInterface;
use Switon\ComposerExtra\ComposerExtraInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ContainerInterface;

/**
 * Loads `extra.switon.tools` into {@see ToolsInterface}.
 *
 * Guidance: an id that is already registered stays.
 *
 * @see ToolDiscoveryInterface
 */
class ToolDiscovery implements ToolDiscoveryInterface
{
    #[Autowired] protected ComposerExtraInterface $composerExtra;
    #[Autowired] protected ContainerInterface $container;
    #[Autowired] protected ToolsInterface $tools;

    public function discover(): void
    {
        foreach ($this->composerExtra->collect('switon.tools') as $id => $class) {
            /** @var ToolInterface $tool */
            $tool = $this->container->get($class);
            $this->tools->addTool((string) $id, $tool, false);
        }
    }
}
