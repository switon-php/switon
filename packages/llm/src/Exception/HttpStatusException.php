<?php

declare(strict_types=1);

namespace Switon\Llm\Exception;

use Exception as PhpException;
use Throwable;

use function is_int;
use function is_string;
use function mb_strcut;
use function trim;

/**
 * Non-2xx LLM HTTP response (`getStatusCode()` / `getResponseBody()`).
 *
 * Guidance: Keep `status_code`/`body` in context; used placeholders are dropped from context.
 */
class HttpStatusException extends HttpException
{
    public static function of(string|Throwable $message = '', array $context = [], int $code = 0, ?PhpException $previous = null): static
    {
        $statusCode = $context['status_code'] ?? null;
        $body = $context['body'] ?? null;
        $exception = parent::of($message, $context, $code, $previous);
        if (is_int($statusCode)) {
            $exception->context['status_code'] = $statusCode;
        }
        if (is_string($body)) {
            $exception->context['body'] = $body;
        }

        return $exception;
    }

    public function getStatusCode(): int
    {
        $statusCode = $this->getContext()['status_code'] ?? 0;

        return is_int($statusCode) ? $statusCode : 0;
    }

    public function getResponseBody(): string
    {
        $body = $this->getContext()['body'] ?? '';

        return is_string($body) ? $body : '';
    }

    public function interruptMessage(): string
    {
        return match ($this->getStatusCode()) {
            401 => 'LLM API authentication failed.',
            403 => 'LLM API access forbidden.',
            429 => 'LLM API rate limit exceeded.',
            502 => 'LLM API gateway error.',
            503 => 'LLM API temporarily unavailable.',
            default => $this->defaultInterruptMessage(),
        };
    }

    protected function defaultInterruptMessage(): string
    {
        $status = $this->getStatusCode();
        $detail = trim($this->getMessage());
        if ($status > 0) {
            $detail = $detail === '' ? '(HTTP ' . $status . ')' : $detail . ' (HTTP ' . $status . ')';
        }

        $body = trim($this->getResponseBody());
        if ($body !== '') {
            $detail .= ' — ' . mb_strcut($body, 0, 512);
        }

        return $detail !== '' ? $detail : 'LLM HTTP request failed.';
    }
}
