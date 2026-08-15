<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message;
use Switon\Ai\Message\SystemMessage;
use Switon\Ai\Tests\TestCase;
use Switon\Ai\Message\UserMessage;

#[CoversClass(Message::class)]
class MessageTest extends TestCase
{
    public function testSystemFactory(): void
    {
        $message = SystemMessage::of('You are helpful.');

        $this->assertSame(SystemMessage::Role, $message->role);
        $this->assertSame('You are helpful.', $message->content);
    }

    public function testUserFactory(): void
    {
        $message = UserMessage::of('Hello');

        $this->assertInstanceOf(UserMessage::class, $message);
        $this->assertSame(UserMessage::Role, $message->role);
        $this->assertSame('Hello', $message->wire()[0]['text']);
        $this->assertArrayNotHasKey('content', $message->jsonSerialize());
    }

    public function testAssistantProviderPassbackAndAttributes(): void
    {
        $message = new AssistantMessage('Hi', passback: ['trace_id' => 't-1']);
        $message->attributes = ['compact' => 'skip'];

        $this->assertSame(['trace_id' => 't-1'], $message->passback);
        $this->assertSame(['compact' => 'skip'], $message->attributes);
    }
}
