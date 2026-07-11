<?php

declare(strict_types=1);

namespace Switon\Http\Filter\Event;

use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Http\Filter\AccessLogFilter;
use Throwable;

/**
 * Event emitted when access log writing fails.
 *
 * Log category: <code>switon.http.filter.access.log.write.failed</code>
 *
 * @see \Switon\Http\Filter\AccessLogFilter::onEnd()
 * @see \Switon\Core\FilesystemInterface
 */
#[EventLevel(Severity::ERROR)]
class AccessLogWriteFailed implements SenderAwareInterface
{
    public function __construct(
        public AccessLogFilter $sender,
        public string $file,
        public string $exceptionMessage,
        public string $exceptionClass,
        public ?string $exceptionFile = null,
        public ?int $exceptionLine = null,
    ) {
    }

    public function getSender(): AccessLogFilter
    {
        return $this->sender;
    }

    public static function from(AccessLogFilter $sender, string $file, Throwable $exception): self
    {
        return new self(
            $sender,
            $file,
            $exception->getMessage(),
            $exception::class,
            $exception->getFile(),
            $exception->getLine(),
        );
    }
}
