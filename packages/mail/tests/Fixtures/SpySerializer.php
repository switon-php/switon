<?php

declare(strict_types=1);

namespace Switon\Mail\Tests\Fixtures;

use Switon\Mail\MessageInterface;
use Switon\Mail\Rfc822SerializerInterface;

/**
 * @internal
 */
class SpySerializer implements Rfc822SerializerInterface
{
    public ?MessageInterface $lastMessage = null;

    public ?bool $lastOmitBccInHeaders = null;

    public function serialize(MessageInterface $message, bool $omitBccInHeaders): string
    {
        $this->lastMessage = $message;
        $this->lastOmitBccInHeaders = $omitBccInHeaders;

        return "Subject: test\r\n\r\nbody";
    }

    public function dotStuff(string $payload): string
    {
        return $payload;
    }
}
