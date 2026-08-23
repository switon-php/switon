<?php

declare(strict_types=1);

namespace Switon\Agency;

use JsonSerializable;

/**
 * Local handoff `tool_result` wire (`{status, message, payload}`) — not inter-agent inbox.
 *
 * Guidance: Child→parent inbox is {@see \Switon\Agency\Thread\TaskFinishSignal}; this ack does not end the run.
 *
 * @see \Switon\Ai\ToolCall
 * @see \Switon\Agency\Thread\TaskFinishSignal
 */
class DeliverAck implements JsonSerializable
{
    public const string STATUS = 'delivered';

    public const string MESSAGE = 'Dispatched. Continue if needed; do not poll — reply arrives on inbox.';

    /**
     * @param array<string, mixed> $payload Tool-specific fields
     */
    protected function __construct(protected array $payload = [], protected string $message = self::MESSAGE)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function of(array $payload = [], string $message = self::MESSAGE): self
    {
        return new self($payload, $message);
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function message(): string
    {
        return $this->message;
    }

    /**
     * @return array{status: string, message: string, payload: array<string, mixed>}
     */
    public function jsonSerialize(): array
    {
        return [
            'status' => self::STATUS,
            'message' => $this->message,
            'payload' => $this->payload,
        ];
    }
}
