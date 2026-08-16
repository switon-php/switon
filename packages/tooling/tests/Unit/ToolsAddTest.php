<?php

declare(strict_types=1);

namespace Switon\Tooling\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolInterface;
use Switon\Ai\ToolSchema;
use Switon\Tooling\Tests\ToolTestCase;
use Switon\Tooling\Tools;
use Switon\Tooling\ToolsInterface;

#[CoversClass(Tools::class)]
class ToolsAddTest extends ToolTestCase
{
    public function testAddSkipsExistingUnlessOverwrite(): void
    {
        /** @var Tools $tools */
        $tools = $this->container->get(ToolsInterface::class);
        $first = $this->stubTool('first');
        $second = $this->stubTool('second');

        $tools->addTool('probe_add', $first, false);
        $tools->addTool('probe_add', $second, false);

        $this->assertSame('first', $tools->pick(['probe_add'])['probe_add']->definition['description']);

        $tools->addTool('probe_add', $second, true);
        $this->assertSame('second', $tools->pick(['probe_add'])['probe_add']->definition['description']);
    }

    protected function stubTool(string $description): ToolInterface
    {
        return new class ($description) implements ToolInterface {
            public function __construct(protected string $description)
            {
            }

            public function schema(string $name): ?ToolSchema
            {
                return new ToolSchema($this, description: $this->description, properties: [], required: []);
            }

            public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
            {
                unset($call, $arguments, $agent);

                return null;
            }

            public function isReadonly(string $name, array $arguments): bool
            {
                unset($name, $arguments);

                return false;
            }
        };
    }
}
