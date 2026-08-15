<?php

declare(strict_types=1);

namespace Switon\Llm\Tests\Unit\Fixtures;

use CurlHandle;
use Switon\Llm\Http\CurlInterface;

/** @internal Test double for {@see CurlInterface}. */
class StubCurl implements CurlInterface
{
    public string $responseBody = '';

    public int $statusCode = 200;

    /** @var list<string> */
    public array $responseHeaders = [];

    public bool $execFails = false;

    public bool $initFails = false;

    /** @var list<string> */
    public array $streamChunks = [];

    /** @var array<int, mixed> */
    public array $options = [];

    /**
     * Invoked before each stream chunk index (including 0). Return false to stop.
     *
     * @var null|callable(int): void
     */
    public mixed $beforeChunk = null;

    protected mixed $writeCallback = null;

    protected mixed $headerCallback = null;

    protected mixed $progressCallback = null;

    public function init(): CurlHandle|false
    {
        if ($this->initFails) {
            return false;
        }

        return curl_init();
    }

    public function setopt(CurlHandle $handle, int $option, mixed $value): bool
    {
        $this->options[$option] = $value;

        if ($option === CURLOPT_WRITEFUNCTION) {
            $this->writeCallback = $value;
        }

        if ($option === CURLOPT_HEADERFUNCTION) {
            $this->headerCallback = $value;
        }

        if ($option === CURLOPT_XFERINFOFUNCTION) {
            $this->progressCallback = $value;
        }

        return curl_setopt($handle, $option, $value);
    }

    public function exec(CurlHandle $handle): string|bool
    {
        if ($this->execFails) {
            return false;
        }

        if ($this->headerCallback !== null) {
            foreach ($this->responseHeaders as $header) {
                ($this->headerCallback)($handle, $header);
            }
        }

        if ($this->writeCallback !== null) {
            foreach ($this->streamChunks as $index => $chunk) {
                if ($this->beforeChunk !== null) {
                    ($this->beforeChunk)($index);
                }

                if ($this->progressCallback !== null) {
                    $abort = ($this->progressCallback)($handle, 0, 0, 0, 0);
                    if ($abort !== 0) {
                        return false;
                    }
                }

                $written = ($this->writeCallback)($handle, $chunk);
                if ($written === 0) {
                    return false;
                }
            }

            return true;
        }

        return $this->responseBody;
    }

    public function errno(CurlHandle $handle): int
    {
        return CURLE_OPERATION_TIMEDOUT;
    }

    public function error(CurlHandle $handle): string
    {
        return 'timeout';
    }

    public function getinfo(CurlHandle $handle, int $option = 0): mixed
    {
        if ($option === CURLINFO_RESPONSE_CODE) {
            return $this->statusCode;
        }

        return 0;
    }
}
