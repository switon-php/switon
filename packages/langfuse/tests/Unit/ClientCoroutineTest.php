<?php

declare(strict_types=1);

namespace Switon\Langfuse\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\Runtime;
use Switon\HttpClient\HttpClientInterface;
use Switon\Langfuse\Client;
use Switon\Langfuse\Observability\Span;
use Switon\Langfuse\Otlp;
use Switon\Langfuse\Tests\TestCase;

#[CoversClass(Client::class)]
class ClientCoroutineTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is required for coroutine tests');
        }

        parent::setUp();
    }

    /**
     * Under curl hooks an export yields, so two exports for one trace overlap and Langfuse drops
     * observations. The consumer coroutine must send them one at a time, in push order.
     */
    public function testCoroutineModeSendsExportsSeriallyInPushOrder(): void
    {
        \Swoole\Coroutine\run(function (): void {
            Runtime::setCoroutineEnabled(true);

            try {
                $client = $this->make(Client::class, [
                    'publicKey' => 'pk-test',
                    'secretKey' => 'sk-test',
                    'baseUrl' => 'https://cloud.langfuse.com',
                    'httpClient' => $this->httpClient,
                    'logger' => $this->logger,
                ]);

                foreach ([1, 2, 3] as $step) {
                    $client->ingest([
                        new Span('step-' . $step, Otlp::traceId('trace-1'), sprintf('%016d', $step), 1.0, 2.0),
                    ]);
                }

                // Queued, not dropped: the worker has not run yet at this point in the same coroutine.
                $client->shutdown();

                $this->assertCount(3, $this->httpClient->posts);
                $this->assertSame(
                    ['step-1', 'step-2', 'step-3'],
                    array_map(
                        static fn (array $post): string => (string) ($post['body']['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['name'] ?? ''),
                        $this->httpClient->posts,
                    ),
                );
            } finally {
                Runtime::setCoroutineEnabled(false);
            }
        });
    }

    public function testBlockingModeStillSendsInline(): void
    {
        Runtime::setCoroutineEnabled(false);

        $client = $this->make(Client::class, [
            'publicKey' => 'pk-test',
            'secretKey' => 'sk-test',
            'baseUrl' => 'https://cloud.langfuse.com',
            'httpClient' => $this->httpClient,
            'logger' => $this->logger,
        ]);

        $client->ingest([new Span('generation', Otlp::traceId('trace-1'), 'a1b2c3d4e5f60718', 1.0, 2.0)]);

        // Without coroutines the export is synchronous, so no flush is needed.
        $this->assertCount(1, $this->httpClient->posts);
        $this->assertSame(
            'Basic ' . base64_encode('pk-test:sk-test'),
            $this->httpClient->posts[0]['headers'][HttpClientInterface::HEADER_AUTHORIZATION] ?? '',
        );
    }
}
