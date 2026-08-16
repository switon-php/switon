<?php

declare(strict_types=1);

namespace Switon\Tooling\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolInterface;
use Switon\Ai\ToolSchema;
use Switon\ComposerExtra\ComposerExtraInterface;
use Switon\Tooling\Tests\ToolTestCase;
use Switon\Tooling\Tool\BashTool;
use Switon\Tooling\ToolDiscovery;
use Switon\Tooling\ToolsInterface;

#[CoversClass(ToolDiscovery::class)]
class ToolDiscoveryTest extends ToolTestCase
{
    public function testDiscoverWithEmptyCollectIsNoOp(): void
    {
        /** @var ToolsInterface $tools */
        $tools = $this->container->get(ToolsInterface::class);

        $discovery = $this->make(ToolDiscovery::class, [
            'composerExtra' => $this->composerExtra([]),
            'container' => $this->container,
            'tools' => $tools,
        ]);
        $discovery->discover();

        $this->assertArrayNotHasKey('probe_extra', $tools->pick(['probe_extra']));
    }

    public function testDiscoverAddsMissingIdAndKeepsExisting(): void
    {
        /** @var ToolsInterface $tools */
        $tools = $this->container->get(ToolsInterface::class);
        $this->assertInstanceOf(BashTool::class, $tools->get('bash')?->handler);

        $discovery = $this->make(ToolDiscovery::class, [
            'composerExtra' => $this->composerExtra([
                'bash' => ToolDiscoveryProbeTool::class,
                'probe_extra' => ToolDiscoveryProbeTool::class,
            ]),
            'container' => $this->container,
            'tools' => $tools,
        ]);
        $discovery->discover();

        $this->assertInstanceOf(BashTool::class, $tools->get('bash')?->handler);
        $this->assertSame('probe-from-extra', $tools->pick(['probe_extra'])['probe_extra']->definition['description']);
    }

    /**
     * @param array<int|string, string> $tools
     */
    protected function composerExtra(array $tools): ComposerExtraInterface
    {
        $extra = $this->createStub(ComposerExtraInterface::class);
        $extra->method('collect')->willReturnCallback(
            fn (string $path) => $path === 'switon.tools' ? $tools : [],
        );

        return $extra;
    }
}

final class ToolDiscoveryProbeTool implements ToolInterface
{
    public function schema(string $name): ?ToolSchema
    {
        return new ToolSchema($this, description: 'probe-from-extra', properties: [], required: []);
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        unset($agent, $call, $arguments);

        return null;
    }

    public function isReadonly(string $name, array $arguments): bool
    {
        return true;
    }
}
