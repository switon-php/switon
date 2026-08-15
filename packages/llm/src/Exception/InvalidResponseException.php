<?php

declare(strict_types=1);

namespace Switon\Llm\Exception;

use Switon\Llm\Exception as LlmException;

/**
 * Use when a provider response cannot be parsed into completion data.
 */
class InvalidResponseException extends LlmException
{
}
