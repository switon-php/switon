<?php

declare(strict_types=1);

namespace Switon\Agency\Exception;

use Switon\Agency\Exception as AgentException;

/** Task or inbox delivery could not be accepted by the target worker. */
class DeliveryException extends AgentException
{
    public const REASON_FULL = 'full';

    /** @param array<string, mixed> $context */
    public function __construct(string $message, public string $reason, array $context = [])
    {
        parent::__construct($message, ['reason' => $reason, ...$context]);
    }

    /** @return never */
    public static function inboxFull(): void
    {
        throw new self('Timed out enqueueing delivery (inbox full).', self::REASON_FULL);
    }
}
