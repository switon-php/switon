<?php

declare(strict_types=1);

namespace Switon\Ai\Exception;

use Switon\Ai\Exception as AiException;
use Throwable;

/** The call did not run. Stored as {@see \Switon\Ai\ToolResult::$content}. */
class ToolDeniedException extends AiException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        string|Throwable $message = 'This tool is not allowed.',
        array $context = [],
        int $code = 0,
        ?\Exception $previous = null,
    ) {
        parent::__construct($message, $context, $code, $previous);
    }
}
