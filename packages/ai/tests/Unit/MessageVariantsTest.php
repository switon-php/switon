<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\DeveloperMessage;
use Switon\Ai\Part\RefusalPart;
use Switon\Ai\Message\FoldedMessage;
use Switon\Ai\Part\PinnedPart;
use Switon\Ai\Message\SystemMessage;
use Switon\Ai\Tests\TestCase;
use Switon\Ai\ToolCall;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\ToolResult;
use Switon\Ai\Message\UserMessage;
use Switon\Core\Json;

#[CoversClass(SystemMessage::class)]
#[CoversClass(DeveloperMessage::class)]
#[CoversClass(FoldedMessage::class)]
#[CoversClass(RefusalPart::class)]
#[CoversClass(ToolMessage::class)]
class MessageVariantsTest extends TestCase
{
    public function testSystemDeveloperAndFoldedWireAsText(): void
    {
        $this->assertSame(
            [['type' => 'text', 'text' => 'Be careful.']],
            SystemMessage::of('Be careful.')->wire(),
        );
        $this->assertSame(
            [['type' => 'text', 'text' => 'Plan first.']],
            DeveloperMessage::of('Plan first.')->wire(),
        );

        $folded = FoldedMessage::of('Earlier: hello');
        $this->assertSame(UserMessage::Role, $folded->role);
        $this->assertSame([['type' => 'text', 'text' => 'Earlier: hello']], $folded->wire());

        $withPin = new FoldedMessage('Earlier: hello', [
            new PinnedPart('skill:empty', ''),
            new PinnedPart('skill:live', 'body'),
        ]);
        $this->assertSame([
            ['type' => 'text', 'text' => 'Earlier: hello'],
            ['type' => 'text', 'text' => 'body'],
        ], $withPin->wire());
    }

    public function testRefusalPartStaysOffWireAndRoundTrips(): void
    {
        $part = RefusalPart::of('I cannot help with that.');

        $this->assertSame([], (new AssistantMessage(null, [], null, [], [$part]))->wire());
        $decoded = RefusalPart::decode(Json::parse(Json::stringify($part)));
        $this->assertSame('I cannot help with that.', $decoded->refusal);
    }

    public function testToolMessageCloneIsolatesResults(): void
    {
        $message = ToolMessage::of([
            ToolResult::of(new ToolCall('call_1', 'echo'), 'ok'),
        ]);
        $copy = clone $message;
        $copy->results[0]->content = 'changed';

        $this->assertSame('ok', $message->results[0]->content);
        $this->assertSame([], $message->wire());
    }
}
