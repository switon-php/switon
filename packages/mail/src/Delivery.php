<?php

declare(strict_types=1);

namespace Switon\Mail;

/**
 * SMTP send request for the default mailer pipeline.
 *
 * The fields describe a single in-flight send and are read once by the connection.
 */
class Delivery
{
    /**
     * @param list<string> $recipients
     */
    public function __construct(
        public string $host,
        public int $port,
        public Encryption $encryption,
        public string $username,
        public string $password,
        public float $timeout,
        public string $from,
        public array $recipients,
        public string $payload,
    ) {
    }
}
