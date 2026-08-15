<?php

declare(strict_types=1);

namespace Switon\Llm\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\Exception as AiException;
use Switon\Ai\Exception\ContextLengthExceededException;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Thread;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Llm\AbstractLlm;
use Switon\Llm\ContextOverflowChecker;
use Switon\Llm\ContextOverflowCheckerInterface;
use Switon\Llm\Event\CompletionFailed;
use Switon\Llm\Exception\HttpStatusException;
use Switon\Llm\Http\HttpClient;
use Switon\Llm\OpenAi;
use Switon\Llm\Tests\TestCase;
use Switon\Llm\Tests\Unit\Fixtures\StubCurl;
use Switon\Llm\Tests\Unit\Fixtures\StubHttpClient;
use Switon\Testing\EventDispatcher as TestEventDispatcher;

#[CoversClass(ContextOverflowChecker::class)]
#[CoversClass(AbstractLlm::class)]
#[CoversClass(OpenAi::class)]
class ContextOverflowCheckerTest extends TestCase
{
    public static function overflowProvider(): array
    {
        return [
            'openai code' => ['context_length_exceeded', 'something else'],
            'openai message' => [null, 'This model\'s maximum context length is 128000 tokens.'],
            'anthropic' => [null, 'prompt is too long: 200001 tokens > 200000 maximum'],
            'moonshot' => [null, 'exceeded model token limit: 8192'],
            'qwen' => ['InvalidParameter', 'Range of input length should be [1, 30720]'],
            'chinese' => [null, '请求的 tokens 超过了模型最大上下文'],
        ];
    }

    #[DataProvider('overflowProvider')]
    public function testMatchesOverflow(?string $code, string $message): void
    {
        $this->assertTrue($this->make(ContextOverflowCheckerInterface::class)->matches($code, $message));
    }

    public static function otherProvider(): array
    {
        return [
            'quota' => [null, 'quota exceeded'],
            'rate' => [null, 'rate limit reached'],
            'tpm' => [null, 'tokens per minute limit exceeded'],
            'max tokens param' => [null, 'max_tokens is too large: 999999'],
        ];
    }

    #[DataProvider('otherProvider')]
    public function testIgnoresOtherFailures(?string $code, string $message): void
    {
        $this->assertFalse($this->make(ContextOverflowCheckerInterface::class)->matches($code, $message));
    }

    public function testExtraNeedleMatchesCustomPhrase(): void
    {
        $this->container->set(ContextOverflowCheckerInterface::class, [
            'class' => ContextOverflowChecker::class,
            'needles' => ['', 1, '厂商自定义超限'],
        ]);

        $checker = $this->container->get(ContextOverflowCheckerInterface::class);
        $this->assertFalse($checker->matches(null, 'quota exceeded'));
        $this->assertTrue($checker->matches(null, '厂商自定义超限'));
    }

    public function testCompleteRaisesContextLengthFromProviderPayload(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'error' => [
                'message' => 'This model\'s maximum context length is 128000 tokens. However, you requested 130000 tokens.',
                'code' => 'context_length_exceeded',
            ],
        ], JSON_THROW_ON_ERROR);
        $llm = $this->make(OpenAi::class, [
            'httpClient' => $httpClient,
            'baseUrl' => 'https://api.openai.com/v1',
            'apiKey' => 'test-key',
            'model' => 'gpt-4o-mini',
            'window' => 128_000,
        ]);

        try {
            $llm->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('hi'))), Charter::of());
            $this->fail('Expected ContextLengthExceededException.');
        } catch (ContextLengthExceededException $e) {
            $this->assertInstanceOf(\Switon\Ai\Exception::class, $e);
            $this->assertStringContainsString('maximum context length', $e->getMessage());
            $this->assertSame('gpt-4o-mini', $e->getContext()['model'] ?? null);
            $this->assertSame(128_000, $e->getContext()['window'] ?? null);
        }
    }

    public function testCompletePromotesHttpStatusBody(): void
    {
        $curl = new StubCurl();
        $curl->statusCode = 400;
        $curl->responseBody = '{"error":{"code":"context_length_exceeded","message":"maximum context length is 8192"}}';
        $llm = $this->make(OpenAi::class, [
            'httpClient' => $this->make(HttpClient::class, ['curl' => $curl]),
            'baseUrl' => 'https://api.openai.com/v1',
            'apiKey' => 'test-key',
            'model' => 'gpt-4o-mini',
            'window' => 128_000,
        ]);

        try {
            $llm->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('hi'))), Charter::of());
            $this->fail('Expected ContextLengthExceededException.');
        } catch (ContextLengthExceededException $e) {
            $previous = $e->getPrevious();
            $this->assertInstanceOf(HttpStatusException::class, $previous);
            $this->assertSame(400, $previous->getStatusCode());
            $this->assertSame('gpt-4o-mini', $e->getContext()['model'] ?? null);
            $this->assertSame(128_000, $e->getContext()['window'] ?? null);
        }

        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);
        $failed = $dispatcher->getEventsByClass(CompletionFailed::class);
        $this->assertCount(1, $failed);
        $this->assertInstanceOf(ContextLengthExceededException::class, $failed[0]->error);
        $this->assertSame(400, $failed[0]->httpStatus);
    }

    /** @return array<string, array{string}> */
    public static function otherHttpBodyProvider(): array
    {
        return [
            'not json' => ['not-json'],
            'scalar' => ['null'],
            'no error object' => ['{}'],
            'empty message' => ['{"error":{"message":""}}'],
        ];
    }

    #[DataProvider('otherHttpBodyProvider')]
    public function testCompleteLeavesANonOverflowHttpError(string $body): void
    {
        $curl = new StubCurl();
        $curl->statusCode = 400;
        $curl->responseBody = $body;
        $llm = $this->make(OpenAi::class, [
            'httpClient' => $this->make(HttpClient::class, ['curl' => $curl]),
            'baseUrl' => 'https://api.openai.com/v1',
            'apiKey' => 'test-key',
            'model' => 'gpt-4o-mini',
            'window' => 128_000,
        ]);

        $this->expectException(HttpStatusException::class);
        $llm->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('hi'))), Charter::of());
    }
}
