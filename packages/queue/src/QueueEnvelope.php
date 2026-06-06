<?php

declare(strict_types=1);

namespace Switon\Queue;

use JsonSerializable;

use function array_filter;
use function get_object_vars;

/**
 * Queue message envelope.
 *
 * Guidance: `QueueEnvelope::of()` initializes body/headers; protocol/meta can be carried by properties or headers.
 *
 * Road-signs:
 * - body for runner payload
 * - headers for transport metadata
 * - queue/message_id/timestamp set by Queue::push()
 *
 * @see \Switon\Queue\QueueInterface
 * @see \Switon\Queue\Queue
 */
class QueueEnvelope implements JsonSerializable
{
    public string $message_id = '';
    public string $queue = '';

    /** Optional protocol/meta fields. */
    public ?string $type = null;
    public ?string $correlation_id = null;
    public ?string $reply_to = null;
    public ?string $expiration = null;

    /** @var array<string, mixed> */
    public array $body = [];

    /** @var array<string, mixed> */
    public array $headers = [];

    public int $timestamp = 0;

    /**
     * Creates an envelope with initialized body and headers arrays.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $headers
     */
    public static function of(array $body = [], array $headers = []): self
    {
        $envelope = new self();
        $envelope->body = $body;
        $envelope->headers = $headers;

        return $envelope;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter(get_object_vars($this), static fn (mixed $value): bool => $value !== null);
    }
}
