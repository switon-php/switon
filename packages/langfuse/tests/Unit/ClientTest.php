<?php

declare(strict_types=1);

namespace Switon\Langfuse\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Switon\HttpClient\HttpClientInterface;
use Switon\HttpClient\Request;
use Switon\HttpClient\Response;
use Switon\Langfuse\Client;
use Switon\Langfuse\Observability\Span;
use Switon\Langfuse\Tests\TestCase;

#[CoversClass(Client::class)]
class ClientTest extends TestCase
{
    public function testIngestSkipsWhenBaseUrlEmpty(): void
    {
        $client = $this->make(Client::class, [
            'publicKey' => 'pk-test',
            'secretKey' => 'sk-test',
            'httpClient' => $this->httpClient,
            'logger' => $this->logger,
        ]);

        $client->ingest([$this->span()]);

        $this->assertSame('', $client->baseUrl());
        $this->assertSame([], $this->httpClient->posts);
    }

    public function testIngestSkipsWhenCredentialsEmpty(): void
    {
        $client = $this->make(Client::class, [
            'baseUrl' => 'https://cloud.langfuse.com',
            'httpClient' => $this->httpClient,
            'logger' => $this->logger,
        ]);

        $client->ingest([$this->span()]);
        $client->ingest([$this->span('other')]);

        $this->assertSame([], $this->httpClient->posts);
        $this->assertCount(1, $this->logger->logs);
        $this->assertStringContainsString('public or secret key is empty', $this->logger->logs[0]['message']);
    }

    public function testIngestPostsOtlpJsonWithBasicAuth(): void
    {
        $client = $this->make(Client::class, [
            'publicKey' => 'pk-test',
            'secretKey' => 'sk-test',
            'baseUrl' => 'https://cloud.langfuse.com',
            'httpClient' => $this->httpClient,
            'logger' => $this->logger,
        ]);

        $client->ingest([$this->span()]);

        $this->assertCount(1, $this->httpClient->posts);
        $post = $this->httpClient->posts[0];
        $this->assertSame('https://cloud.langfuse.com/api/public/otel/v1/traces', $post['url']);
        $this->assertSame(
            'Basic ' . base64_encode('pk-test:sk-test'),
            $post['headers'][HttpClientInterface::HEADER_AUTHORIZATION] ?? '',
        );

        $otlp = $post['body']['resourceSpans'][0]['scopeSpans'][0]['spans'][0];
        $this->assertSame('generation', $otlp['name']);
        $this->assertSame(['code' => 0], $otlp['status']);
    }

    public function testIngestLogsRejectedSpansFromPartialSuccess(): void
    {
        $response = new Response(
            new Request('POST', 'https://cloud.langfuse.com/api/public/otel/v1/traces', '{}', [], []),
            ['HTTP/1.1 200 OK', 'Content-Type: application/json'],
            '{"partialSuccess":{"rejectedSpans":1,"errorMessage":"invalid attribute"}}',
        );

        $http = $this->createMock(HttpClientInterface::class);
        $http->expects($this->once())->method('post')->willReturn($response);
        $client = $this->make(Client::class, [
            'publicKey' => 'pk-test',
            'secretKey' => 'sk-test',
            'baseUrl' => 'https://cloud.langfuse.com',
            'httpClient' => $http,
            'logger' => $this->logger,
        ]);

        $client->ingest([$this->span()]);

        $this->assertCount(1, $this->logger->logs);
        $this->assertStringContainsString('Langfuse export rejected', $this->logger->logs[0]['message']);
    }

    public function testIngestIgnoresABodyThatIsNotAPartialSuccess(): void
    {
        $client = $this->clientWithBody('not-json');
        $client->ingest([$this->span()]);
        $client = $this->clientWithBody('{"ok":true}');
        $client->ingest([$this->span()]);
        $client = $this->clientWithBody('{"partialSuccess":{"rejectedSpans":0}}');
        $client->ingest([$this->span()]);

        $this->assertSame([], $this->logger->logs);
    }

    public function testShutdownBeforeAnyExportDoesNothing(): void
    {
        $client = $this->make(Client::class, [
            'publicKey' => 'pk-test',
            'secretKey' => 'sk-test',
            'baseUrl' => 'https://cloud.langfuse.com',
            'httpClient' => $this->httpClient,
            'logger' => $this->logger,
        ]);

        $client->shutdown();

        $this->assertSame([], $this->httpClient->posts);
    }

    public function testIngestSwallowsTransportErrors(): void
    {
        $this->httpClient->throwOnPost = new RuntimeException('network down');

        $client = $this->make(Client::class, [
            'publicKey' => 'pk-test',
            'secretKey' => 'sk-test',
            'baseUrl' => 'https://cloud.langfuse.com',
            'httpClient' => $this->httpClient,
            'logger' => $this->logger,
        ]);

        $client->ingest([$this->span()]);

        $this->assertCount(1, $this->logger->logs);
        $this->assertStringContainsString('Langfuse export failed', $this->logger->logs[0]['message']);
    }

    public function testIngestAddsHttpsSchemeToHostWithoutOne(): void
    {
        $client = $this->make(Client::class, [
            'publicKey' => 'pk-test',
            'secretKey' => 'sk-test',
            'baseUrl' => 'cloud.langfuse.com',
            'httpClient' => $this->httpClient,
            'logger' => $this->logger,
        ]);

        $client->ingest([$this->span()]);

        $this->assertSame('https://cloud.langfuse.com/api/public/otel/v1/traces', $this->httpClient->posts[0]['url']);
    }

    public function testIngestKeepsAnExplicitScheme(): void
    {
        $client = $this->make(Client::class, [
            'publicKey' => 'pk-test',
            'secretKey' => 'sk-test',
            'baseUrl' => 'https://us.cloud.langfuse.com',
            'httpClient' => $this->httpClient,
            'logger' => $this->logger,
        ]);

        $client->ingest([$this->span()]);

        $this->assertSame('https://us.cloud.langfuse.com/api/public/otel/v1/traces', $this->httpClient->posts[0]['url']);
    }

    protected function clientWithBody(string $body): Client
    {
        $response = new Response(
            new Request('POST', 'https://cloud.langfuse.com/api/public/otel/v1/traces', '{}', [], []),
            ['HTTP/1.1 200 OK', 'Content-Type: application/json'],
            $body,
        );
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects($this->once())->method('post')->willReturn($response);

        return $this->make(Client::class, [
            'publicKey' => 'pk-test',
            'secretKey' => 'sk-test',
            'baseUrl' => 'https://cloud.langfuse.com',
            'httpClient' => $http,
            'logger' => $this->logger,
        ]);
    }

    protected function span(string $traceSeed = 'trace-1'): Span
    {
        return new Span('generation', \Switon\Langfuse\Otlp::traceId($traceSeed), 'a1b2c3d4e5f60718', 1.0, 2.0);
    }
}
