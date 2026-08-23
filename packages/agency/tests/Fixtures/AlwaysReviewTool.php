<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Fixtures;

use Switon\Ai\AbstractExtension;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;

/** Test double: every call asks the host, suspending as {@see \Switon\Ai\Suspension\ToolReviewInterrupt}. */
class AlwaysReviewTool extends AbstractExtension
{
    /** @var list<string> */
    public array $seen = [];

    public function reviewTool(AgentInterface $agent, ToolCall $call): bool
    {
        $this->seen[] = $call->id;

        return true;
    }
}
