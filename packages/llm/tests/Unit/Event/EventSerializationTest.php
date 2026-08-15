<?php

declare(strict_types=1);

namespace Switon\Llm\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\StreamDelta;
use Switon\Ai\Thread;
use Switon\Ai\TurnStatus;
use Switon\Llm\Event\CompletionDelta;
use Switon\Llm\Event\CompletionFailed;
use Switon\Llm\Event\CompletionRequesting;
use Switon\Llm\Event\CompletionResponded;
use Switon\Llm\Event\HttpFailed;
use Switon\Llm\Event\HttpRequested;
use Switon\Llm\Event\HttpRequesting;
use Switon\Llm\Event\HttpStreaming;
use Switon\Llm\Http\HttpClient;
use Switon\Llm\Http\HttpResponse;
use Switon\Llm\OpenAi;
use Switon\Llm\Tests\TestCase;

#[CoversClass(CompletionRequesting::class)]
#[CoversClass(CompletionResponded::class)]
#[CoversClass(CompletionDelta::class)]
#[CoversClass(CompletionFailed::class)]
#[CoversClass(HttpRequesting::class)]
#[CoversClass(HttpRequested::class)]
#[CoversClass(HttpStreaming::class)]
#[CoversClass(HttpFailed::class)]
#[CoversClass(HttpResponse::class)]
class EventSerializationTest extends TestCase
{
    public function testCompletionLifecycleEventsJsonSerialize(): void
    {
        $chatCompletions = $this->make(OpenAi::class, [
            'baseUrl' => 'https://api.openai.com/v1',
            'model' => 'gpt-test',
            'window' => 128_000,
        ]);
        $roundInput = UserMessage::of('');
        $roundCompletion = Completion::of($roundInput);
        $thread = new Thread('test');
        $thread->turns[] = \Switon\Ai\Turn::of($roundCompletion->completeId, status: TurnStatus::Running, model: 'gpt-test');
        $requesting = new CompletionRequesting($chatCompletions, $thread, $roundCompletion, Charter::of());

        $respondedCompletion = Completion::of(UserMessage::of(''), new AssistantMessage('Hello'));
        $respondedCompletion->completeId = $requesting->completion->completeId;
        $respondedCompletion->finishReason = 'stop';

        $responded = new CompletionResponded(
            $chatCompletions,
            new Thread('test'),
            $respondedCompletion,
            Charter::of(),
        );

        $this->assertNotSame('', $requesting->completion->completeId);
        $this->assertSame([
            'thread_id' => $requesting->thread->id,
            'turn_id' => $roundCompletion->completeId,
            'step' => 1,
            'complete_id' => $requesting->completion->completeId,
            'model' => 'gpt-test',
            'stream' => false,
            'prompt_count' => 0,
            'tool_count' => 0,
        ], $requesting->jsonSerialize());

        $respondedPayload = $responded->jsonSerialize();
        $this->assertSame([
            'thread_id' => $responded->thread->id,
            'turn_id' => '',
            'step' => 1,
            'complete_id' => $requesting->completion->completeId,
            'content_length' => 5,
            'reasoning_length' => 0,
            'finish_reason' => 'stop',
            'timing' => [],
        ], $respondedPayload);
        $this->assertArrayNotHasKey('content', $respondedPayload);
        $this->assertArrayNotHasKey('reasoning', $respondedPayload);
        $this->assertSame($requesting->completion->completeId, $respondedCompletion->completeId);
    }

    public function testStreamAndFailureEventsJsonSerialize(): void
    {
        $chatCompletions = $this->make(OpenAi::class, [
            'baseUrl' => 'https://api.openai.com/v1',
            'model' => 'gpt-test',
            'window' => 128_000,
        ]);
        $roundCompletion = Completion::of(UserMessage::of(''));
        $requesting = new CompletionRequesting($chatCompletions, new Thread('test'), $roundCompletion, Charter::of(), stream: true);
        $streamDelta = new StreamDelta(id: 'chat-1', content: 'Hi', finishReason: null);
        $error = new RuntimeException('boom');

        $stream = new CompletionDelta(
            $chatCompletions,
            new Thread('test'),
            $streamDelta,
            $roundCompletion,
            Charter::of(),
            index: 3,
        );
        $failed = new CompletionFailed(
            $chatCompletions,
            new Thread('test'),
            $roundCompletion,
            $error,
            Charter::of(),
            httpStatus: 502,
            stream: true,
        );

        $streamPayload = $stream->jsonSerialize();
        $this->assertSame([
            'thread_id' => $requesting->thread->id,
            'complete_id' => $requesting->completion->completeId,
            'index' => 3,
            'response_id' => 'chat-1',
            'content_length' => 2,
            'reasoning_length' => 0,
            'finish_reason' => null,
            'at' => null,
        ], $streamPayload);
        $this->assertArrayNotHasKey('content', $streamPayload);
        $this->assertArrayNotHasKey('reasoning', $streamPayload);

        $this->assertSame([
            'thread_id' => $requesting->thread->id,
            'turn_id' => '',
            'step' => 1,
            'complete_id' => $requesting->completion->completeId,
            'model' => '',
            'stream' => true,
            'error' => 'boom',
            'http_status' => 502,
        ], $failed->jsonSerialize());
    }

    public function testHttpEventsJsonSerialize(): void
    {
        $httpClient = $this->make(HttpClient::class);
        $error = new RuntimeException('timeout');

        $requesting = new HttpRequesting($httpClient, 'https://api.test/v1/chat/completions', [], '{}', stream: true);
        $requested = new HttpRequested(
            $httpClient,
            'https://api.test/v1/chat/completions',
            [],
            '{}',
            60,
            true,
            new HttpResponse(200, 'ok'),
            0.12,
        );
        $streaming = new HttpStreaming(
            $httpClient,
            'https://api.test/v1/chat/completions',
            [],
            '{}',
            60,
            true,
            'data: hi',
        );
        $failed = new HttpFailed(
            $httpClient,
            'https://api.test/v1/chat/completions',
            [],
            '{}',
            60,
            true,
            $error,
            0.5,
            new HttpResponse(503, 'unavailable'),
        );

        $this->assertSame([
            'url' => 'https://api.test/v1/chat/completions',
            'stream' => true,
            'timeout' => 60,
        ], $requesting->jsonSerialize());

        $this->assertSame([
            'url' => 'https://api.test/v1/chat/completions',
            'status_code' => 200,
            'stream' => true,
            'elapsed' => 0.12,
        ], $requested->jsonSerialize());

        $streamingPayload = $streaming->jsonSerialize();
        $this->assertSame([
            'url' => 'https://api.test/v1/chat/completions',
            'line_length' => 8,
        ], $streamingPayload);
        $this->assertArrayNotHasKey('line', $streamingPayload);

        $this->assertSame([
            'url' => 'https://api.test/v1/chat/completions',
            'stream' => true,
            'status_code' => 503,
            'error' => 'timeout',
            'elapsed' => 0.5,
        ], $failed->jsonSerialize());
    }
}
