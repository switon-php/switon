<?php

declare(strict_types=1);

namespace Switon\Llm\Exception;

/**
 * Raised when curl transport fails before a complete HTTP response is received.
 *
 * @see \Switon\Llm\Http\HttpClient
 */
class HttpTransportException extends HttpException
{
}
