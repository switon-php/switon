<?php

declare(strict_types=1);

namespace Switon\Llm\Exception;

/**
 * Use when a provider returns a structured error payload instead of completion data.
 */
class ProviderErrorException extends InvalidResponseException
{
}
