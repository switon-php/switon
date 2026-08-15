<?php

declare(strict_types=1);

namespace Switon\Llm\Exception;

use Switon\Llm\Exception as LlmException;

/**
 * Base exception for LLM HTTP transport failures.
 *
 * @see \Switon\Llm\Exception\HttpStatusException Non-2xx HTTP responses
 * @see \Switon\Llm\Exception\HttpTransportException Curl/connection failures
 */
class HttpException extends LlmException
{
}
