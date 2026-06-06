<?php

declare(strict_types=1);

namespace Switon\Mail\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Mail\Encryption;

class EncryptionTest extends TestCase
{
    public function testBackedValues(): void
    {
        $this->assertSame('none', Encryption::None->value);
        $this->assertSame('starttls', Encryption::StartTls->value);
        $this->assertSame('ssl', Encryption::Ssl->value);
    }

    public function testTryFromKnownStrings(): void
    {
        $this->assertSame(Encryption::None, Encryption::tryFrom('none'));
        $this->assertSame(Encryption::StartTls, Encryption::tryFrom('starttls'));
        $this->assertSame(Encryption::Ssl, Encryption::tryFrom('ssl'));
    }

    public function testTryFromUnknownReturnsNull(): void
    {
        $this->assertNull(Encryption::tryFrom('not-a-mode'));
    }
}
