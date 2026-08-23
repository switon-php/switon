<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Switon\Agency\Tests\Fixtures\RecordingTool;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolInterface;
use Switon\Ai\ToolSchema;

#[CoversClass(RecordingTool::class)]
class RecordingToolTest extends TestCase
{
    public function testSchemaHandlerIsTheRecorder(): void
    {
        $inner = new RecordingToolStub();
        $recorder = new RecordingTool($inner);

        $schema = $recorder->schema('echo');

        $this->assertInstanceOf(ToolSchema::class, $schema);
        $this->assertSame($recorder, $schema->handler);
        $this->assertSame($inner, $inner->schema('echo')->handler);
    }

    public function testCallRecordsThenDelegates(): void
    {
        $inner = new RecordingToolStub();
        $recorder = new RecordingTool($inner);
        $agent = $this->createStub(AgentInterface::class);
        $call = new ToolCall('c1', 'echo', ['text' => 'hi']);

        $result = $recorder->call($agent, $call, ['text' => 'hi']);

        $this->assertSame(['echo' => 'hi'], $result);
        $this->assertCount(1, $recorder->calls);
        $this->assertSame('echo', $recorder->calls[0]['name']);
        $this->assertSame(['text' => 'hi'], $recorder->calls[0]['arguments']);
        $this->assertTrue($recorder->isReadonly('echo', []));
    }
}

class RecordingToolStub implements ToolInterface
{
    public function schema(string $name): ToolSchema
    {
        return new ToolSchema($this, description: 'echo', properties: [
            'text' => ['type' => 'string'],
        ]);
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        unset($agent, $call);

        return ['echo' => $arguments['text']];
    }

    public function isReadonly(string $name, array $arguments): bool
    {
        unset($name, $arguments);

        return true;
    }
}
