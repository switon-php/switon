<?php

declare(strict_types=1);

namespace Switon\Agency\Event;

use JsonSerializable;
use Switon\Agency\Agent;
use Switon\Agency\Instruct\Response;
use Switon\Ai\ToolCall;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Instruct run accepted {@see Response::NAME} (not a real {@see \Switon\Tooling\ToolsInterface::call()}).
 *
 * Log category: `switon.agency.instruct.success`
 *
 * @see \Switon\Ai\InstructorInterface::instruct()
 * @see InstructFail
 */
#[EventLevel(Severity::DEBUG)]
class InstructSuccess implements JsonSerializable, SenderAwareInterface
{
    /**
     * @param array<string, mixed> $result Accepted response envelope (`code` / `msg` / `data`).
     *        {@see \Switon\Agency\Instructor::instruct()} unwraps `data` only on return.
     */
    public function __construct(public Agent $sender, public ToolCall $toolCall, public array $result)
    {
    }

    public function getSender(): Agent
    {
        return $this->sender;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->toolCall->id,
            'name' => Response::NAME,
            'agent' => $this->sender->threadId(),
            'result' => $this->result,
        ];
    }
}
