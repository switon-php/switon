<?php

declare(strict_types=1);

namespace Switon\Langfuse\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Thread;
use Switon\Ai\TokenUsage;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Langfuse\Client;
use Switon\Langfuse\Observability\LlmListener;
use Switon\Langfuse\Tests\Fixtures\StubLlm;
use Switon\Langfuse\Tests\TestCase;
use Switon\Llm\Event\CompletionResponded;

/**
 * Wires the listener to the real client so this asserts the OTLP JSON that actually leaves the
 * process: endpoint, id shapes, and attribute encoding fail loudly here.
 */
#[CoversClass(Client::class)]
class OtlpWireFormatTest extends TestCase
{
    public function testListenerExportIsOtlpJsonOnTheOtelEndpoint(): void
    {
        $this->container->set(\Switon\Langfuse\ClientInterface::class, [
            'class' => Client::class,
            'publicKey' => 'pk-lf-test',
            'secretKey' => 'sk-lf-test',
            'baseUrl' => 'https://cloud.langfuse.com',
        ]);

        $thread = new Thread('wire-thread');
        $completion = Completion::of(UserMessage::of('ping'));
        $completion->output = new AssistantMessage('pong');
        $completion->usage = new TokenUsage(input: 3, output: 5);
        $thread->turns[] = Turn::of('1ea435b1-83df-4130-a57c-a97bd9848d96', [$completion], TurnStatus::Running, 'gpt');

        $listener = $this->make(LlmListener::class);
        $listener->onCompletionResponded(new CompletionResponded(new StubLlm(), $thread, $completion, Charter::of()));

        $this->assertCount(1, $this->httpClient->posts);
        $post = $this->httpClient->posts[0];
        $this->assertSame('https://cloud.langfuse.com/api/public/otel/v1/traces', $post['url']);
        $this->assertSame('application/json', $post['headers']['Content-Type']);
        $this->assertSame('Basic ' . base64_encode('pk-lf-test:sk-lf-test'), $post['headers']['Authorization']);

        $spans = $post['body']['resourceSpans'][0]['scopeSpans'][0]['spans'];
        $this->assertCount(3, $spans, 'trace root, its step, and the generation of that step');
        $this->assertSame('turn', $spans[0]['name']);
        $this->assertSame('step-1', $spans[1]['name']);
        $this->assertSame($spans[0]['spanId'], $spans[1]['parentSpanId'] ?? '');
        $this->assertSame($spans[1]['spanId'], $spans[2]['parentSpanId'] ?? '');
        $span = $spans[2];
        $this->assertSame('1ea435b183df4130a57ca97bd9848d96', $span['traceId']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $span['spanId']);
        $this->assertMatchesRegularExpression('/^\d{19}$/', $span['startTimeUnixNano']);
        $this->assertMatchesRegularExpression('/^\d{19}$/', $span['endTimeUnixNano']);
        $this->assertSame(['code' => 0], $span['status']);
        $this->assertSame('generation', $this->attribute($span, 'langfuse.observation.type'));
        $this->assertSame('wire-thread', $this->attribute($span, 'langfuse.session.id'));
        $this->assertSame('wire-thread', $this->attribute($span, 'langfuse.trace.name'));
    }

    protected function attribute(array $span, string $key): ?string
    {
        foreach ($span['attributes'] as $attribute) {
            if ($attribute['key'] === $key) {
                return $attribute['value']['stringValue'] ?? null;
            }
        }

        return null;
    }
}
