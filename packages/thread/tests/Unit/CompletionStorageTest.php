<?php

declare(strict_types=1);

namespace Switon\Thread\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Ai\Completion;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\SystemMessage;
use Switon\Ai\Signal\UserAbortSignal;
use Switon\Ai\Signal\UserSignal;
use Switon\Core\Json;

class CompletionStorageTest extends TestCase
{
    public function testSignalRoundTrip(): void
    {
        $message = new UserAbortSignal(body: 'The user stopped the run.');

        $encoded = $message->jsonSerialize();
        $this->assertSame(UserAbortSignal::class, $encoded['class']);
        $decoded = $encoded['class']::decode($encoded);

        $this->assertInstanceOf(UserAbortSignal::class, $decoded);
        $this->assertSame('user_abort', $decoded->name);
        $this->assertSame($message->fields, $decoded->fields);
        $this->assertSame($message->body, $decoded->body);
    }

    public function testCompletionRoundTripPreservesSignalClosureInput(): void
    {
        $input = new UserAbortSignal(body: 'The user stopped the run.');
        $completion = Completion::of($input, null);

        $restored = Completion::decode(Json::parse(Json::stringify($completion)));

        $this->assertInstanceOf(UserAbortSignal::class, $restored->input);
        $this->assertNull($restored->output);
    }

    public function testCompletionRoundTripPreservesTaskInput(): void
    {
        $task = new UserSignal('form_ask', [
            'kind' => 'answer',
            'id' => 'f1',
        ], 'payload', 'payload');
        $completion = Completion::of($task, new AssistantMessage('ack'));

        $restored = Completion::decode(Json::parse(Json::stringify($completion)));

        $this->assertInstanceOf(UserSignal::class, $restored->input);
        $this->assertSame('payload', $restored->input->body);
    }

    public function testSystemMessageCarriesClass(): void
    {
        $encoded = SystemMessage::of('system note')->jsonSerialize();

        $this->assertSame(SystemMessage::class, $encoded['class']);
        $this->assertSame('system', $encoded['role']);

        $decoded = $encoded['class']::decode($encoded);
        $this->assertInstanceOf(SystemMessage::class, $decoded);
        $this->assertSame('system note', $decoded->content);
    }
}
