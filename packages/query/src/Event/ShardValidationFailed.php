<?php

declare(strict_types=1);

namespace Switon\Query\Event;

use JsonSerializable;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Query\ShardingJoinValidatorInterface;
use Throwable;

/**
 * Event emitted when join shard validation fails.
 *
 * Log category: `switon.query.shard.validation.failed`
 *
 * Join tuples are produced by the query layer; sharding keeps them opaque
 * (table target is typically a string or a query value object).
 *
 * @see \Switon\Query\ShardingJoinValidator Typical emitter
 * @see \Switon\Sharding\ShardingManager
 */
#[EventLevel(Severity::ERROR)]
class ShardValidationFailed implements JsonSerializable, SenderAwareInterface
{
    /**
     * @param string $reason Failure reason.
     * @param array<string, list<string>> $mainShards Main shards.
     * @param array<string, mixed> $context Sharding context.
     * @param list<array{0: string|object, 1: string|null, 2: string|null, 3: string|null}> $joins Join metadata.
     * @param string $exceptionMessage Exception message.
     * @param string $exceptionClass Exception class.
     * @param string|null $exceptionFile Exception file.
     * @param int|null $exceptionLine Exception line.
     */
    public function __construct(
        public ShardingJoinValidatorInterface $sender,
        public string  $reason,
        public array   $mainShards,
        public array   $context,
        public array   $joins = [],
        public string  $exceptionMessage = '',
        public string  $exceptionClass = '',
        public ?string $exceptionFile = null,
        public ?int    $exceptionLine = null,
    ) {
    }

    public function getSender(): ShardingJoinValidatorInterface
    {
        return $this->sender;
    }

    /**
     * @param array<string, list<string>> $mainShards
     * @param array<string, mixed> $context
     * @param list<array{0: string|object, 1: string|null, 2: string|null, 3: string|null}> $joins
     */
    public static function from(
        ShardingJoinValidatorInterface $sender,
        array $mainShards,
        array $context,
        array $joins,
        Throwable $exception
    ): self {
        return new self(
            $sender,
            $exception->getMessage(),
            $mainShards,
            $context,
            $joins,
            $exception->getMessage(),
            $exception::class,
            $exception->getFile(),
            $exception->getLine(),
        );
    }

    /**
     * Returns the validation failure payload.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'reason' => $this->reason,
            'mainShards' => $this->mainShards,
            'context' => $this->context,
            'exceptionClass' => $this->exceptionClass,
            'exceptionMessage' => $this->exceptionMessage,
            'exceptionFile' => $this->exceptionFile,
            'exceptionLine' => $this->exceptionLine,
        ];
    }
}
