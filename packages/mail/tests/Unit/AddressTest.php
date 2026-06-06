<?php

declare(strict_types=1);

namespace Switon\Mail\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Mail\Address;

class AddressTest extends TestCase
{
    public function testEmailOnly(): void
    {
        $a = new Address('user@example.com');
        $this->assertSame('user@example.com', $a->getEmail());
        $this->assertNull($a->getName());
    }

    public function testEmailWithDisplayName(): void
    {
        $a = new Address('user@example.com', 'User Name');
        $this->assertSame('user@example.com', $a->getEmail());
        $this->assertSame('User Name', $a->getName());
    }
}
