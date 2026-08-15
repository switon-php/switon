<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\DeveloperMessage;
use Switon\Ai\Message;
use Switon\Ai\Part\RefusalPart;
use Switon\Ai\Tests\TestCase;
use Switon\Ai\ToolCall;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\ToolResult;
use Switon\Ai\Message\UserMessage;
use Switon\Core\Json;
use TypeError;

#[CoversClass(Message::class)]
#[CoversClass(AssistantMessage::class)]
class MessageEncodeDecodeTest extends TestCase
{
    public function testEncodeAndDecodeAssistantMessageWithToolCalls(): void
    {
        $message = new AssistantMessage(
            'Working.',
            [
                new ToolCall('call_1', 'get_weather', ['location' => 'SF'], passback: ['type' => 'function']),
            ],
            'Think first.',
            ['trace_id' => 'trace-1'],
        );

        $encoded = $message->jsonSerialize();
        $this->assertSame(['trace_id' => 'trace-1'], $encoded['passback']);

        $decoded = AssistantMessage::decode(Json::parse(Json::stringify($message)));

        $this->assertInstanceOf(AssistantMessage::class, $decoded);
        $this->assertSame('Working.', $decoded->content);
        $this->assertSame('Think first.', $decoded->reasoning);
        $this->assertSame(['trace_id' => 'trace-1'], $decoded->passback);
        $this->assertFalse(array_key_exists('class', get_object_vars($decoded)));
        $this->assertCount(1, $decoded->toolCalls);
        $this->assertSame('call_1', $decoded->toolCalls[0]->id);
        $this->assertSame('get_weather', $decoded->toolCalls[0]->name);
        $this->assertSame(['location' => 'SF'], $decoded->toolCalls[0]->arguments);
        $this->assertSame(['type' => 'function'], $decoded->toolCalls[0]->passback);
    }

    public function testDecodePreservesNullAndEmptyReasoning(): void
    {
        $withNull = AssistantMessage::decode([
            'role' => AssistantMessage::Role,
            'content' => 'Hi',
            'toolCalls' => [],
            'reasoning' => null,
            'parts' => [],
        ]);
        $this->assertNull($withNull->reasoning);

        $withEmpty = AssistantMessage::decode([
            'role' => AssistantMessage::Role,
            'content' => 'Hi',
            'toolCalls' => [],
            'reasoning' => '',
            'parts' => [],
        ]);
        $this->assertSame('', $withEmpty->reasoning);
    }

    public function testDecodeRestoresToolMessageFields(): void
    {
        $message = ToolMessage::of([
            ToolResult::of(new ToolCall('call_1', 'get_weather'), '{"temp":72}'),
        ]);

        $decoded = ToolMessage::decode(Json::parse(Json::stringify($message)));

        $this->assertInstanceOf(ToolMessage::class, $decoded);
        $this->assertSame('call_1', $decoded->results[0]->id);
        $this->assertSame('get_weather', $decoded->results[0]->name);
        $this->assertSame('{"temp":72}', $decoded->results[0]->content);
    }

    public function testDecodeRestoresPassback(): void
    {
        $decoded = AssistantMessage::decode([
            'role' => AssistantMessage::Role,
            'content' => 'Hi',
            'toolCalls' => [],
            'passback' => ['trace_id' => 'trace-1'],
            'parts' => [],
        ]);

        $this->assertSame(['trace_id' => 'trace-1'], $decoded->passback);
    }

    public function testDecodeRestoresAttributes(): void
    {
        $decoded = AssistantMessage::decode([
            'role' => AssistantMessage::Role,
            'content' => 'Hi',
            'attributes' => ['compact' => 'skip'],
            'toolCalls' => [
                [
                    'class' => ToolCall::class,
                    'id' => 'call_1',
                    'name' => 'echo',
                    'arguments' => [],
                    'attributes' => ['ui' => 'chip'],
                ],
            ],
            'parts' => [],
        ]);

        $this->assertSame(['compact' => 'skip'], $decoded->attributes);
        $this->assertSame(['ui' => 'chip'], $decoded->toolCalls[0]->attributes);
    }

    public function testEncodeDeveloperMessageKeepsRole(): void
    {
        $encoded = get_object_vars(DeveloperMessage::of('Plan carefully.'));

        $this->assertSame('developer', $encoded['role']);
        $this->assertSame('Plan carefully.', $encoded['content']);
    }

    public function testUserMessageRoundTrip(): void
    {
        $decoded = UserMessage::decode(Json::parse(Json::stringify(UserMessage::of('Hello'))));

        $this->assertInstanceOf(UserMessage::class, $decoded);
        $this->assertSame('Hello', $decoded->wire()[0]['text']);
        $this->assertArrayNotHasKey('content', $decoded->jsonSerialize());
    }

    public function testAssistantRefusalPartRoundTrip(): void
    {
        $message = new AssistantMessage(null, [], null, [], [RefusalPart::of('I cannot help with that.')]);

        $decoded = AssistantMessage::decode(Json::parse(Json::stringify($message)));

        $this->assertNull($decoded->content);
        $this->assertInstanceOf(RefusalPart::class, $decoded->parts[0]);
        $this->assertSame('I cannot help with that.', $decoded->parts[0]->refusal);
        $this->assertSame([], $decoded->wire());
    }

    public function testDecodeLegacyAssistantContentWithoutParts(): void
    {
        $decoded = AssistantMessage::decode([
            'role' => AssistantMessage::Role,
            'content' => 'Hi',
            'toolCalls' => [],
            'parts' => [],
        ]);

        $this->assertSame('Hi', $decoded->content);
        $this->assertSame([], $decoded->parts);
    }

    public function testAssistantEmptyContentYieldsEmptyWireAndText(): void
    {
        $empty = new AssistantMessage('');
        $null = new AssistantMessage(null);

        $this->assertSame([], $empty->wire());
        $this->assertSame([], $null->wire());
        // No text blocks → null, same as any other message with nothing readable.
        $this->assertNull($empty->text());
        $this->assertNull($null->text());
    }

    public function testAssistantWireEmitsTextBlock(): void
    {
        $message = new AssistantMessage('Hello');

        $this->assertSame([['type' => 'text', 'text' => 'Hello']], $message->wire());
        $this->assertSame('Hello', $message->text());
    }

    public function testAssistantCloneIsolatesToolCallsAndParts(): void
    {
        $message = new AssistantMessage(
            'Hi',
            [new ToolCall('call_1', 'echo', ['n' => 1])],
            parts: [RefusalPart::of('no')],
        );
        $copy = clone $message;
        $copy->toolCalls[0]->arguments['n'] = 9;
        $copy->parts[0]->refusal = 'changed';

        $this->assertSame(1, $message->toolCalls[0]->arguments['n']);
        $this->assertSame('no', $message->parts[0]->refusal);
    }

    public function testAssistantDecodeRejectsNonArrayParts(): void
    {
        $this->expectException(TypeError::class);

        AssistantMessage::decode([
            'role' => AssistantMessage::Role,
            'content' => 'Hi',
            'toolCalls' => [],
            'parts' => ['skip'],
        ]);
    }
}
