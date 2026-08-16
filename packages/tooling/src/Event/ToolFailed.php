<?php

declare(strict_types=1);

namespace Switon\Tooling\Event;

use JsonSerializable;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolResult;
use Switon\Core\Exception as CoreException;
use Switon\Core\Json;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Tooling\ToolsInterface;
use Throwable;

use function is_string;

/**
 * Emitted by {@see ToolsInterface::call()} when the {@see \Switon\Ai\ToolResult} is an error.
 *
 * Guidance: the same call still returns that result. Schema bind failures never enter `call()`.
 *
 * Log category: `switon.tooling.tool.failed`
 */
#[EventLevel(Severity::INFO)]
class ToolFailed implements JsonSerializable, SenderAwareInterface
{
    public function __construct(
        public ToolsInterface $sender,
        public ToolCall $call,
        public AgentInterface $agent,
        public ToolResult $result,
    ) {
    }

    public function getSender(): ToolsInterface
    {
        return $this->sender;
    }

    /** Failure text: exception message, or the result content when it is not an exception. */
    public function message(): string
    {
        $content = $this->result->content;
        if ($content instanceof Throwable) {
            return $content->getMessage();
        }
        if (is_string($content)) {
            return $content;
        }

        return Json::stringify($content);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'thread_id' => $this->agent->threadId(),
            'name' => $this->call->name,
            'tool_call_id' => $this->call->id,
            'message' => $this->message(),
            'context' => $this->result->content instanceof CoreException ? $this->result->content->getContext() : [],
        ];
    }
}
