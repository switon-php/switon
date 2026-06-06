<?php

declare(strict_types=1);

namespace Switon\Mail;

/**
 * Immutable SMTP send request for the default mailer pipeline.
 */
readonly class Delivery
{
    /**
     * @param list<string> $recipients
     */
    public function __construct(
        public string     $host,
        public int        $port,
        public Encryption $encryption,
        public string     $username,
        public string     $password,
        public float      $timeout,
        public string     $from,
        public array      $recipients,
        public string     $payload,
    ) {
    }
}
