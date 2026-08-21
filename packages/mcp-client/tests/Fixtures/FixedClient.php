<?php

declare(strict_types=1);

namespace Switon\McpClient\Tests\Fixtures;

use Switon\Ai\ToolSchema;
use Switon\McpClient\ClientInterface;

/**
 * Client double whose catalog and brief are fixed.
 */
class FixedClient implements ClientInterface
{
    /** @var list<bool> */
    public array $refreshes = [];

    /**
     * @param array<string, ToolSchema> $tools
     */
    public function __construct(
        public string $line = '',
        public array $tools = [],
    ) {
    }

    public function brief(): string
    {
        return $this->line;
    }

    public function onNotification(callable $handler): void
    {
    }

    public function ping(): void
    {
    }

    public function listTools(bool $refresh = false): array
    {
        $this->refreshes[] = $refresh;

        return $this->tools;
    }

    public function callTool(string $name, array $arguments = [], ?callable $shouldAbort = null, bool $raw = true): mixed
    {
        return ['content' => []];
    }
}
