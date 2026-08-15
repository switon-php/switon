<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Tests\TestCase;
use Switon\Ai\ToolCall;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\ToolResult;
use Switon\Core\Json;

#[CoversClass(ToolMessage::class)]
class ToolMessageTest extends TestCase
{
    public function testDecodeRestoresResultsFromStorageBag(): void
    {
        $message = ToolMessage::of([
            ToolResult::of(new ToolCall('call_1', 'echo'), 'pong'),
        ]);

        $decoded = ToolMessage::decode(Json::parse(Json::stringify($message)));

        $this->assertSame(ToolMessage::Role, $decoded->role);
        $this->assertCount(1, $decoded->results);
        $this->assertSame('call_1', $decoded->results[0]->id);
        $this->assertSame('echo', $decoded->results[0]->name);
        $this->assertSame('pong', $decoded->results[0]->content);
        $this->assertSame([], $decoded->wire());
    }

    public function testDecodeEmptyResults(): void
    {
        $decoded = ToolMessage::decode([
            'class' => ToolMessage::class,
            'role' => ToolMessage::Role,
            'results' => [],
            'attributes' => [],
        ]);

        $this->assertSame([], $decoded->results);
    }
}
