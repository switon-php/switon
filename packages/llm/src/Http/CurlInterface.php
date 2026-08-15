<?php

declare(strict_types=1);

namespace Switon\Llm\Http;

use CurlHandle;

/**
 * Thin curl abstraction for LLM HTTP transport tests.
 *
 * @see \Switon\Llm\Http\Curl
 * @see \Switon\Llm\Http\HttpClient
 */
interface CurlInterface
{
    public function init(): CurlHandle|false;

    public function setopt(CurlHandle $handle, int $option, mixed $value): bool;

    public function exec(CurlHandle $handle): string|bool;

    public function errno(CurlHandle $handle): int;

    public function error(CurlHandle $handle): string;

    public function getinfo(CurlHandle $handle, int $option = 0): mixed;
}
