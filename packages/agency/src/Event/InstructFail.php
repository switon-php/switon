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
use Switon\Llm\Exception\ApiFailedException;

/**
 * Instruct run reported {@see Response::NAME} with a non-ok `code`
 * (not a real {@see \Switon\Tooling\ToolsInterface::call()}).
 *
 * Log category: `switon.agency.instruct.fail`
 *
 * @see \Switon\Ai\InstructorInterface::instruct()
 * @see InstructSuccess
 */
#[EventLevel(Severity::INFO)]
class InstructFail implements JsonSerializable, SenderAwareInterface
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public Agent    $sender,
        public ToolCall $toolCall,
        public string   $failureCode,
        public string   $message,
        public array    $details = [],
    ) {
    }

    public static function fromException(Agent $sender, ToolCall $toolCall, ApiFailedException $exception): self
    {
        $code = $toolCall->arguments['code'] ?? 'other';
        if (!is_string($code) || $code === Response::OK || !in_array($code, Response::FAIL_CODES, true)) {
            $code = 'other';
        }

        return new self(
            $sender,
            $toolCall,
            $code,
            $exception->reason,
            $exception->details,
        );
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
            'code' => $this->failureCode,
            'message' => $this->message,
            'details' => $this->details,
        ];
    }
}
