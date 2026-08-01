<?php

declare(strict_types=1);

namespace Switon\Mcp\Tests\Fixtures;

use Switon\Mcp\Exception\InvalidParamsException;
use Switon\Mcp\ProtocolHandler;
use Switon\Mcp\ToolInterface;
use Switon\Mcp\ToolResult;

/** Lets binder failures leave {@see ProtocolHandler::call()} as JSON-RPC errors. */
class RethrowingProtocolHandler extends ProtocolHandler
{
    protected function call(ToolInterface $tool, string $name, array $arguments): ToolResult
    {
        $result = parent::call($tool, $name, $arguments);
        if ($result->content instanceof InvalidParamsException) {
            throw $result->content;
        }

        return $result;
    }
}
