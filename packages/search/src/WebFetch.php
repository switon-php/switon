<?php

declare(strict_types=1);

namespace Switon\Search;

use Switon\Core\Attribute\Autowired;
use Switon\Core\WebFetcherInterface;
use Switon\LlmHtml\LlmHtmlInterface;
use Switon\Tooling\Spiller;
use ValueError;

use function html_entity_decode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function json_last_error;
use function ltrim;
use function mb_check_encoding;
use function mb_convert_encoding;
use function mb_strcut;
use function ord;
use function parse_url;
use function preg_match;
use function preg_replace;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strcasecmp;
use function strlen;
use function strpos;
use function strtolower;
use function strtoupper;
use function substr;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;
use const JSON_ERROR_NONE;
use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_UNICODE;

/** Downloads one URL and returns readable text, or the raw body when `$raw` is set. */
class WebFetch implements WebFetchInterface
{
    #[Autowired] protected int $timeout = 15;
    /** Download cap in MiB. Further body is cut; {@see Spiller::ceilingExceeded()} is appended once. */
    #[Autowired] protected int $ceiling = 32;

    #[Autowired] protected LlmHtmlInterface $llmHtml;
    #[Autowired] protected WebFetcherInterface $chrome;

    /**
     * Fetch a URL and return readable page text (or raw body when `$raw`).
     *
     * @return array<string, mixed>
     */
    public function fetch(string $url, callable $shouldAbort, bool $raw = false): array
    {
        $this->assertHttpUrl($url);
        $download = $this->download($url, $shouldAbort);

        return $this->extract($url, $download['body'], $download, $raw);
    }

    protected function assertHttpUrl(string $url): void
    {
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
        $host = parse_url($url, PHP_URL_HOST);
        if (($scheme !== 'http' && $scheme !== 'https') || !is_string($host) || $host === '') {
            Exception::raise('URL must be an absolute http or https address.');
        }
    }

    protected function ceilingBytes(): int
    {
        return max(1, $this->ceiling) * 1024 * 1024;
    }

    /**
     * @return array{body: string, status_code: int, content_type: string}
     */
    protected function download(string $url, callable $shouldAbort): array
    {
        $response = $this->chrome->fetch($url, 'GET', [], null, [
            WebFetcherInterface::TIMEOUT => (float) $this->timeout,
            WebFetcherInterface::SHOULD_ABORT => $shouldAbort,
        ]);
        $body = $response['body'];
        $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];

        return [
            'body' => $this->hardCut($body, strlen($body) > $this->ceilingBytes()),
            'status_code' => $response['status'],
            'content_type' => $this->headerValue($headers, 'content-type'),
        ];
    }

    /**
     * @param array<string, mixed> $headers
     */
    protected function headerValue(array $headers, string $name): string
    {
        foreach ($headers as $headerName => $value) {
            if (is_string($headerName) && is_string($value) && strcasecmp($headerName, $name) === 0) {
                return $value;
            }
        }

        return '';
    }

    protected function hardCut(string $body, bool $over): string
    {
        $cap = $this->ceilingBytes();
        if (!$over && strlen($body) <= $cap) {
            return $body;
        }

        return $this->truncateUtf8($body, $cap) . Spiller::ceilingExceeded($cap);
    }

    /**
     * @param array{body: string, status_code: int, content_type: string} $fetch
     *
     * @return array<string, mixed>
     */
    protected function extract(string $url, string $body, array $fetch, bool $raw = false): array
    {
        $body = $this->normalizeEncoding($body, $fetch['content_type']);

        if ($raw) {
            return $this->payload($url, $this->parseHtmlTitle($body), $this->keepHardCut($body, $body), $fetch, 'raw', false);
        }

        if ($this->isJson($fetch['content_type'], $body)) {
            return $this->payload($url, '', $this->keepHardCut($body, $this->formatJson($body)), $fetch, 'json');
        }

        if (!$this->isHtmlContentType($fetch['content_type'])) {
            return $this->payload($url, '', $this->keepHardCut($body, $body), $fetch, 'text');
        }

        $converted = $this->llmHtml->convert($body, 'markdown');

        return $this->payload(
            $url,
            $converted->title,
            $this->keepHardCut($body, $converted->content),
            $fetch,
            $converted->format,
        );
    }

    protected function truncateUtf8(string $body, int $maxBytes): string
    {
        if (strlen($body) <= $maxBytes) {
            return $body;
        }

        if (mb_check_encoding($body, 'UTF-8')) {
            $cut = mb_strcut($body, 0, $maxBytes, 'UTF-8');
            if ($cut !== '') {
                return $cut;
            }
        }

        $pos = $maxBytes;
        while ($pos > 0 && (ord($body[$pos]) & 0xC0) === 0x80) {
            $pos--;
        }

        return substr($body, 0, $pos);
    }

    protected function parseHtmlTitle(string $body): string
    {
        if (preg_match('/<title[^>]*>([^<]*)<\/title>/is', $body, $match) !== 1) {
            return '';
        }

        return trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    protected function normalizeEncoding(string $body, string $contentType): string
    {
        $charset = null;
        if (preg_match('/charset=["\']?([\w-]+)/i', $contentType, $match) === 1) {
            $charset = $match[1];
        } elseif (preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', $body, $match) === 1) {
            $charset = $match[1];
        }

        if ($charset === null || strtoupper($charset) === 'UTF-8') {
            return $body;
        }

        try {
            $converted = mb_convert_encoding($body, 'UTF-8', $charset);
        } catch (ValueError) {
            return $body;
        }

        return $converted === false ? $body : $converted;
    }

    protected function isJson(string $contentType, string $body): bool
    {
        if (str_contains(strtolower($contentType), 'json')) {
            return true;
        }
        if ($this->isExplicitHtml($contentType)) {
            return false;
        }

        $trimmed = ltrim($body);

        return str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[');
    }

    protected function isExplicitHtml(string $contentType): bool
    {
        $mime = strtolower(trim($contentType));
        $semi = strpos($mime, ';');
        if ($semi !== false) {
            $mime = trim(substr($mime, 0, $semi));
        }

        return $mime === 'text/html' || $mime === 'application/xhtml+xml';
    }

    /**
     * Route HTML (and missing/ambiguous types) into {@see LlmHtmlInterface}.
     * Explicit non-HTML MIME types pass through unchanged.
     */
    protected function isHtmlContentType(string $contentType): bool
    {
        $mime = strtolower(trim($contentType));
        if ($mime === '') {
            return true;
        }

        $semi = strpos($mime, ';');
        if ($semi !== false) {
            $mime = trim(substr($mime, 0, $semi));
        }

        if ($mime === 'text/html' || $mime === 'application/xhtml+xml') {
            return true;
        }

        if ($mime === 'text/plain' || $mime === 'application/octet-stream' || !str_contains($mime, '/')) {
            return true;
        }

        return false;
    }

    protected function formatJson(string $body): string
    {
        $decoded = json_decode($body, true);

        return json_last_error() === JSON_ERROR_NONE
            ? (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            : $body;
    }

    /**
     * @param array{body: string, status_code: int, content_type: string} $fetch
     *
     * @return array<string, mixed>
     */
    protected function payload(
        string $url,
        string $title,
        string $content,
        array $fetch,
        string $format = 'markdown',
        bool $normalize = true,
    ): array {
        if ($normalize) {
            $content = $this->normalizeReadableText($content);
        }

        return [
            'url' => $url,
            'status_code' => $fetch['status_code'],
            'content_type' => $fetch['content_type'],
            'title' => $title,
            'format' => $format,
            'content' => $content,
        ];
    }

    /** Keep the download hard-cut line on extracted text when HTML conversion drops it. */
    protected function keepHardCut(string $source, string $content): string
    {
        $marker = Spiller::ceilingExceeded($this->ceilingBytes());
        if (str_contains($source, $marker) && !str_contains($content, $marker)) {
            return $content . $marker;
        }

        return $content;
    }

    protected function normalizeReadableText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
