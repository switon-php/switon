<?php

declare(strict_types=1);

namespace Switon\Llm\Exception;

use Switon\Llm\Exception as LlmException;

/**
 * Use when an LLM adapter cannot load required local/provider configuration.
 */
class ConfigurationException extends LlmException
{
}
