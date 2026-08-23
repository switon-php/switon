<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Fixtures;

use RuntimeException;
use Switon\Ai\AbstractExtension;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;

/**
 * Lets the first {@see $allowCount} calls through, then throws from `beforeTool`
 * (bubbles to the Loop catch, which closes open tools on the turn).
 */
class ThrowingBeforeTool extends AbstractExtension
{
    public int $seen = 0;

    public function __construct(
        public int $allowCount = 1,
        public string $message = 'tool boom',
    ) {
    }

    public function beforeTool(AgentInterface $agent, ToolCall $call): void
    {
        if ($this->seen >= $this->allowCount) {
            throw new RuntimeException($this->message);
        }
        $this->seen++;
    }
}
