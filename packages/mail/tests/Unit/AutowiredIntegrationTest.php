<?php

declare(strict_types=1);

namespace Switon\Mail\Tests\Unit;

use Switon\Mail\Address;
use Switon\Mail\MailerInterface;
use Switon\Mail\Message;
use Switon\Mail\Rfc822SerializerInterface;
use Switon\Mail\Tests\Fixtures\MailerProbe;
use Switon\Mail\Tests\Fixtures\SpyPoolManager;
use Switon\Mail\Tests\Fixtures\SpySerializer;
use Switon\Pooling\PoolManagerInterface;
use Switon\Mail\Tests\TestCase;

class AutowiredIntegrationTest extends TestCase
{
    protected SpySerializer $spySerializer;

    protected function setUpContainer(): void
    {
        $this->spySerializer = new SpySerializer();
        $this->container->set(Rfc822SerializerInterface::class, $this->spySerializer);
        $this->container->set(PoolManagerInterface::class, new SpyPoolManager());
    }

    public function testMailerResolvesSerializerViaAutowiredProperty(): void
    {
        $mailer = $this->make(MailerProbe::class, [
            'host' => 'smtp.example.com',
            'username' => '',
            'password' => '',
            'from' => 'from@example.com',
        ]);
        $message = Message::compose()
            ->from(new Address('from@example.com'))
            ->to(new Address('to@example.com'))
            ->text('hello')
            ->get();

        $mailer->send($message);

        $this->assertInstanceOf(MailerInterface::class, $mailer);
        $this->assertSame($message, $mailer->lastDelivered);
        $this->assertSame($this->spySerializer, $mailer->probeSerializer());
    }

    public function testMailerStoresAutowiredSerializerOnBaseClass(): void
    {
        $mailer = $this->make(MailerProbe::class, [
            'host' => 'smtp.example.com',
            'username' => '',
            'password' => '',
            'from' => 'from@example.com',
        ]);

        $this->assertSame($this->spySerializer, $mailer->probeSerializer());
    }
}
