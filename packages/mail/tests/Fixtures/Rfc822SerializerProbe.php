<?php

declare(strict_types=1);

namespace Switon\Mail\Tests\Fixtures;

use Switon\Mail\AddressInterface;
use Switon\Mail\Rfc822Serializer;

/**
 * Exposes protected encoding helpers for focused unit tests.
 *
 * @internal
 */
class Rfc822SerializerProbe extends Rfc822Serializer
{
    public function probeDomainFromEmail(string $email): string
    {
        return $this->domainFromEmail($email);
    }

    public function probeFilenameParam(string $filename): string
    {
        return $this->filenameParam($filename);
    }

    public function probeFormatAddress(AddressInterface $address): string
    {
        return $this->formatAddress($address);
    }

    public function probeEncodeSubject(string $subject): string
    {
        return $this->encodeSubject($subject);
    }

    public function probeFoldHeaderValue(string $value): string
    {
        return $this->foldHeaderValue($value);
    }
}
