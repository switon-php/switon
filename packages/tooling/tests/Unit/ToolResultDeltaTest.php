<?php

declare(strict_types=1);

namespace Switon\Tooling\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Ai\AgentInterface;
use Switon\Ai\Event\ToolResultDelta;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolInterface;
use Switon\Ai\ToolSchema;
use Switon\Tooling\Tests\Fixtures\ToolTestAgent;

class ToolResultDeltaTest extends TestCase
{
    public function testJsonSerializeReadsCallIdFromEvent(): void
    {
        $tool = self::tool();

        $event = new ToolResultDelta($tool, 'c1', 'bash', ToolTestAgent::in(), 'hello', 'stdout');

        $this->assertSame([
            'thread_id' => 'test-thread',
            'name' => 'bash',
            'tool_call_id' => 'c1',
            'channel' => 'stdout',
            'bytes' => 5,
        ], $event->jsonSerialize());
        $this->assertSame($tool, $event->getSender());
        $this->assertSame('c1', $event->callId);
    }

    public function testJsonSerializeWithoutCallIdentity(): void
    {
        $tool = self::tool();

        $event = new ToolResultDelta($tool, '', '', ToolTestAgent::in(), 'x', 'stderr');

        $this->assertSame([
            'thread_id' => 'test-thread',
            'name' => '',
            'tool_call_id' => '',
            'channel' => 'stderr',
            'bytes' => 1,
        ], $event->jsonSerialize());
    }

    protected static function tool(): ToolInterface
    {
        return new class () implements ToolInterface {
            public function schema(string $name): ?ToolSchema
            {
                return null;
            }

            public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
            {
                unset($call, $arguments, $agent);

                return null;
            }

            /**
             * @param array<string, mixed> $arguments
             */
            public function isReadonly(string $name, array $arguments): bool
            {
                unset($name, $arguments);

                return false;
            }
        };
    }
}
