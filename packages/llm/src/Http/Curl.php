<?php

declare(strict_types=1);

namespace Switon\Llm\Http;

use CurlHandle;

use function curl_errno;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt;

/**
 * Native PHP curl adapter for {@see HttpClient}.
 */
class Curl implements CurlInterface
{
    public function init(): CurlHandle|false
    {
        return curl_init();
    }

    public function setopt(CurlHandle $handle, int $option, mixed $value): bool
    {
        return curl_setopt($handle, $option, $value);
    }

    public function exec(CurlHandle $handle): string|bool
    {
        return curl_exec($handle);
    }

    public function errno(CurlHandle $handle): int
    {
        return curl_errno($handle);
    }

    public function error(CurlHandle $handle): string
    {
        return curl_error($handle);
    }

    public function getinfo(CurlHandle $handle, int $option = 0): mixed
    {
        return curl_getinfo($handle, $option);
    }
}
