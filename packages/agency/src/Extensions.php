<?php

declare(strict_types=1);

namespace Switon\Agency;

use Switon\Ai\AgentInterface;
use Switon\Ai\ExtensionInterface;
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\Envelope;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolResult;
use Switon\Ai\Turn;
use Switon\ComposerExtra\ComposerExtraInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ClassScannerInterface;
use Switon\Core\ContainerInterface;

/**
 * Default {@see ExtensionsInterface}: the discovered rows, fanned out per lifecycle point.
 *
 * Discovery is bootstrap-time only (see {@see discover()}), so the hot path is a plain array
 * walk. Rows are resolved from the container, so an extension may inject services.
 *
 * @see ExtensionInterface
 */
class Extensions implements ExtensionsInterface
{
    #[Autowired] protected ComposerExtraInterface $composerExtra;
    #[Autowired] protected ClassScannerInterface $classScanner;
    #[Autowired] protected ContainerInterface $container;

    /**
     * App-side scan paths; packages declare themselves in
     * <code>extra.switon.agent.extensions</code> instead.
     *
     * @var array<int|string, string>
     */
    #[Autowired] protected array $paths = [
        '@app/Agent/Extension/*Extension.php' => 'App\\Agent\\Extension\\*Extension',
    ];

    /** @var list<ExtensionInterface> */
    protected array $extensions = [];

    public function all(): array
    {
        return $this->extensions;
    }

    public function discover(): void
    {
        $classes = [];
        foreach ($this->composerExtra->collect('switon.agent.extensions') as $class) {
            $classes[$class] = true;
        }
        foreach ($this->classScanner->scan($this->paths, null, ExtensionInterface::class) as $class) {
            $classes[$class] = true;
        }

        $extensions = [];
        foreach (array_keys($classes) as $class) {
            /** @var ExtensionInterface $extension */
            $extension = $this->container->get($class);
            $extensions[] = $extension;
        }

        $this->extensions = $extensions;
    }

    public function inbound(AgentInterface $agent, Envelope $envelope): void
    {
        foreach ($this->extensions as $extension) {
            $extension->inbound($agent, $envelope);
        }
    }

    public function beforeTurn(AgentInterface $agent, Turn $turn): void
    {
        foreach ($this->extensions as $extension) {
            $extension->beforeTurn($agent, $turn);
        }
    }

    public function afterTurn(AgentInterface $agent, Turn $turn): void
    {
        foreach ($this->extensions as $extension) {
            $extension->afterTurn($agent, $turn);
        }
    }

    public function beforeModel(AgentInterface $agent, Charter $charter): void
    {
        foreach ($this->extensions as $extension) {
            $extension->beforeModel($agent, $charter);
        }
    }

    public function afterModel(AgentInterface $agent, Completion $completion): void
    {
        foreach ($this->extensions as $extension) {
            $extension->afterModel($agent, $completion);
        }
    }

    public function beforeTool(AgentInterface $agent, ToolCall $call): void
    {
        foreach ($this->extensions as $extension) {
            $extension->beforeTool($agent, $call);
        }
    }

    /** First `true` wins — one extension asking for review is enough. */
    public function reviewTool(AgentInterface $agent, ToolCall $call): bool
    {
        foreach ($this->extensions as $extension) {
            if ($extension->reviewTool($agent, $call)) {
                return true;
            }
        }

        return false;
    }

    public function afterTool(AgentInterface $agent, ToolResult $result): void
    {
        foreach ($this->extensions as $extension) {
            $extension->afterTool($agent, $result);
        }
    }
}
