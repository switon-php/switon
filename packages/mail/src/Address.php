<?php

declare(strict_types=1);

namespace Switon\Mail;

/**
 * Mailbox with optional display name.
 *
 * @see \Switon\Mail\AddressInterface
 */
class Address implements AddressInterface
{
    public function __construct(
        protected string  $email,
        protected ?string $name = null,
    ) {
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getName(): ?string
    {
        return $this->name;
    }
}
