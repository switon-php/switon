<?php

declare(strict_types=1);

namespace Switon\Ai\Exception;

use Switon\Ai\Exception as AiException;

/** Host closed an unfinished call. Stored as {@see \Switon\Ai\ToolResult::$content}. */
class ToolAbortedException extends AiException
{
}
