<?php

declare(strict_types=1);

namespace Switon\Langfuse;

use Psr\Log\LoggerInterface;
use Switon\Core\Attribute\Autowired;
use Switon\HttpClient\HttpClientInterface;
use Switon\HttpClient\RequestOptions;
use Switon\Langfuse\Observability\Span;
use Switon\Sync\TerminationInterface;
use Throwable;

use function base64_encode;
use function is_array;
use function is_string;
use function max;
use function rtrim;
use function str_starts_with;
use function trim;

/**
 * Ships OTLP spans to Langfuse `POST /api/public/otel/v1/traces`.
 *
 * Uses Basic auth (public key / secret key). Failures are logged and swallowed so LLM calls are never blocked.
 *
 * Road-signs:
 * - Enable: a non-empty `baseUrl` is the switch {@see ServiceProvider::boot()} reads to register the listener
 * - Queue: under coroutine hooks batches go through {@see ExportQueue}; `shutdown()` drains before exit
 * - Wire: {@see Otlp} owns ids, timestamps, and attribute value types
 */
class Client implements ClientInterface
{
    #[Autowired] protected HttpClientInterface $httpClient;
    #[Autowired] protected LoggerInterface $logger;
    #[Autowired] protected TerminationInterface $termination;

    #[Autowired] protected string $publicKey = '';
    #[Autowired] protected string $secretKey = '';
    #[Autowired] protected string $baseUrl = '';
    #[Autowired] protected int $timeout = 3;

    #[Autowired] protected string $serviceName = 'switon';

    /** Queued spans held for the consumer coroutine; 0 disables queueing (send inline). */
    #[Autowired] protected int $queueSize = 256;

    protected bool $reportedMissingCredentials = false;

    protected ?ExportQueue $exportQueue = null;

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * @param list<Span> $spans
     */
    public function ingest(array $spans): void
    {
        if ($spans === [] || trim($this->baseUrl) === '') {
            return;
        }

        if (trim($this->publicKey) === '' || trim($this->secretKey) === '') {
            if (!$this->reportedMissingCredentials) {
                $this->reportedMissingCredentials = true;
                $this->logger->warning('Langfuse export skipped: public or secret key is empty');
            }

            return;
        }

        $this->exporter()->push($spans);
    }

    /**
     * Stop accepting exports, wait for every accepted batch, and let the worker exit.
     *
     * Call before process teardown when you want a guarantee that every accepted span was sent.
     */
    public function shutdown(float $timeout = 5.0): void
    {
        if ($this->exportQueue === null) {
            return;
        }

        $this->exportQueue->close();
        $this->exportQueue->flush($timeout);
    }

    /** Under coroutine hooks an export yields, so overlapping writes drop observations; the queue keeps them FIFO. */
    protected function exporter(): ExportQueue
    {
        return $this->exportQueue ??= new ExportQueue(
            fn (array $spans): null => $this->dispatch($spans),
            $this->termination,
            $this->capacity(),
            $this->logger,
        );
    }

    /** `queueSize = 0` means inline export, not a one-slot queue. */
    protected function capacity(): int
    {
        return max(0, $this->queueSize);
    }

    /**
     * Actual HTTP export; runs on the consumer coroutine, or inline when not queueing.
     *
     * @param list<Span> $spans
     */
    protected function dispatch(array $spans): void
    {
        $url = rtrim(self::normalizeBaseUrl($this->baseUrl), '/') . '/api/public/otel/v1/traces';
        $auth = base64_encode(trim($this->publicKey) . ':' . trim($this->secretKey));

        try {
            $response = $this->httpClient->post(
                $url,
                Otlp::payload($this->serviceName, $spans),
                [
                    HttpClientInterface::HEADER_AUTHORIZATION => 'Basic ' . $auth,
                    HttpClientInterface::HEADER_CONTENT_TYPE => 'application/json',
                ],
                RequestOptions::of($this->timeout),
            );

            $this->logExportResponse($response->status(), $response->text());
        } catch (Throwable $e) {
            $this->logger->warning('Langfuse export failed: {message}', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    protected function logExportResponse(int $status, string $body): void
    {
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return;
        }

        $partial = $payload['partialSuccess'] ?? null;
        if (!is_array($partial)) {
            return;
        }

        $rejected = $partial['rejectedSpans'] ?? 0;
        if ((int) $rejected <= 0) {
            return;
        }

        $error = $partial['errorMessage'] ?? '';
        $this->logger->warning('Langfuse export rejected {count} span(s) with HTTP {status}: {message}', [
            'count' => $rejected,
            'status' => $status,
            'message' => is_string($error) ? $error : '',
        ]);
    }

    /** Add `https://` when the configured host omits its scheme. */
    protected static function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if (str_starts_with($baseUrl, 'http://') || str_starts_with($baseUrl, 'https://')) {
            return $baseUrl;
        }

        return 'https://' . $baseUrl;
    }
}
