<?php

declare(strict_types=1);

namespace Switon\Ai\Exception;

use Exception;
use Switon\Ai\Exception as AiException;
use Switon\Ai\ToolCall;

/**
 * Tool-call arguments failed JSON-object decode (not schema bind).
 *
 * @see \Switon\Tooling\Exception\ArgumentBindException
 */
class InvalidToolArgumentsException extends AiException
{
    public function __construct(
        string             $message,
        protected ToolCall $toolCall,
        protected string   $partialJson = '',
        array              $context = [],
        int                $code = 0,
        ?Exception         $previous = null,
    ) {
        parent::__construct($message, $context, $code, $previous);
    }

    public function toolCall(): ToolCall
    {
        return $this->toolCall;
    }

    public function partialJson(): string
    {
        return $this->partialJson;
    }
}
