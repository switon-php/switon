<?php

declare(strict_types=1);

namespace Switon\Ai\Exception;

use Switon\Ai\Exception as AiException;

/**
 * Prompt exceeds the model context window (provider refusal or local window check).
 *
 * @see \Switon\Llm\ContextOverflowCheckerInterface::matches()
 */
class ContextLengthExceededException extends AiException
{
}
