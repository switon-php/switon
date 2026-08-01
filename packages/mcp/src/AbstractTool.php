<?php

declare(strict_types=1);

namespace Switon\Mcp;

/**
 * Thin base for MCP tools: caches {@see ToolSchema} on {@see $schema}.
 *
 * Guidance: subclasses return <code>$this->schema ??= new ToolSchema(...)</code> from {@see schema()}.
 *
 * @see \Switon\Mcp\ToolInterface
 */
abstract class AbstractTool implements ToolInterface
{
    protected ?ToolSchema $schema = null;
}
