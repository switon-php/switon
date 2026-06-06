<?php

declare(strict_types=1);

namespace Switon\Mail\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Mail\Delivery;
use Switon\Mail\Encryption;
use Switon\Mail\Tests\Fixtures\ConnectionProbe;

class ConnectionTest extends TestCase
{
    public function testHandleIsReusedForSameDelivery(): void
    {
        $probe = new ConnectionProbe();

        $first = $probe->handleObjectId();
        $second = $probe->handleObjectId();

        $this->assertSame($first, $second);
    }

    public function testHandleIsReusedWhenDeliverySettingsChange(): void
    {
        $probe = new ConnectionProbe();

        $first = $probe->handleObjectId();
        $second = $probe->handleObjectId();

        $this->assertSame($first, $second);
    }

    public function testSslUsesSmtpsScheme(): void
    {
        $probe = new ConnectionProbe();
        $request = $this->requestFor(host: 'smtp.example.com', encryption: Encryption::Ssl, port: 465);

        $this->assertSame('smtps://smtp.example.com:465', $probe->urlForProbe($request));
    }

    public function testNonSslUsesSmtpScheme(): void
    {
        $probe = new ConnectionProbe();
        $request = $this->requestFor(host: 'smtp.example.com', encryption: Encryption::None, port: 25);

        $this->assertSame('smtp://smtp.example.com:25', $probe->urlForProbe($request));
    }

    protected function requestFor(
        string     $host,
        Encryption $encryption,
        int        $port = 587,
    ): Delivery {
        return new Delivery(
            host: $host,
            port: $port,
            encryption: $encryption,
            username: 'user',
            password: 'pass',
            timeout: 5.0,
            from: 'from@example.com',
            recipients: ['to@example.com'],
            payload: "Subject: test\r\n\r\nbody",
        );
    }
}
