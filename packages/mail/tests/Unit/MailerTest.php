<?php

declare(strict_types=1);

namespace Switon\Mail\Tests\Unit;

use Switon\Mail\Address;
use Switon\Mail\Connection;
use Switon\Mail\Event\MailFailed;
use Switon\Mail\Event\MailSending;
use Switon\Mail\Event\MailSent;
use Switon\Mail\Exception\ConfigurationException;
use Switon\Mail\Exception\InvalidMessageException;
use Switon\Mail\Exception\ProtocolException;
use Switon\Core\ClockInterface;
use Switon\Mail\Mailer;
use Switon\Mail\Message;
use Switon\Mail\MessageInterface;
use Switon\Mail\Tests\Fixtures\MailerProbe;
use Switon\Mail\Tests\Fixtures\SpyConnection;
use Switon\Mail\Tests\Fixtures\SpyEventDispatcher;
use Switon\Mail\Tests\Fixtures\SpyPoolManager;
use Switon\Mail\Tests\Fixtures\SpySerializer;
use Switon\Mail\Tests\TestCase;
use RuntimeException;

class MailerTest extends TestCase
{
    public function testSendThrowsWhenNoRecipients(): void
    {
        $mailer = $this->makeProbeMailer();
        $message = Message::compose()
            ->from(new Address('from@example.com'))
            ->get();

        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('Mail message has no recipients (To/Cc/Bcc).');
        $mailer->send($message);
    }

    public function testSendDelegatesToDeliveryWhenToPresent(): void
    {
        $mailer = $this->makeProbeMailer();
        $message = Message::compose()
            ->from(new Address('from@example.com'))
            ->to(new Address('to@example.com'))
            ->get();

        $mailer->send($message);

        $this->assertSame($message, $mailer->lastDelivered);
    }

    public function testSendAllowsCcOnly(): void
    {
        $mailer = $this->makeProbeMailer();
        $message = Message::compose()
            ->from(new Address('from@example.com'))
            ->cc(new Address('cc@example.com'))
            ->get();

        $mailer->send($message);

        $this->assertSame($message, $mailer->lastDelivered);
    }

    public function testSendAllowsBccOnly(): void
    {
        $mailer = $this->makeProbeMailer();
        $message = Message::compose()
            ->from(new Address('from@example.com'))
            ->bcc(new Address('bcc@example.com'))
            ->get();

        $mailer->send($message);

        $this->assertSame($message, $mailer->lastDelivered);
    }

    public function testSendBuildsDeliveryAndDelegatesToConnection(): void
    {
        $state = $this->makeMailer([
            'host' => 'smtp.example.com',
            'port' => 2525,
            'encryption' => 'none',
            'username' => 'mailer-user',
            'password' => 'mailer-pass',
            'from' => 'sender@example.com',
            'from_name' => 'Sender',
            'timeout' => 3.5,
            'pool_size' => 2,
            'pool_timeout' => 0.5,
        ]);

        $message = Message::compose()
            ->from(new Address('from@example.com'))
            ->to(new Address('to@example.com'))
            ->to(new Address('to@example.com'))
            ->cc(new Address('cc@example.com'))
            ->cc(new Address('to@example.com'))
            ->bcc(new Address('bcc@example.com'))
            ->bcc(new Address('cc@example.com'))
            ->get();

        $state['mailer']->send($message);

        $this->assertCount(1, $state['pool']->addCalls);
        $this->assertCount(1, $state['pool']->acquireCalls);
        $this->assertCount(1, $state['pool']->releaseCalls);
        $this->assertCount(2, $state['events']->events);
        $this->assertInstanceOf(MailSending::class, $state['events']->events[0]);
        $this->assertInstanceOf(MailSent::class, $state['events']->events[1]);

        /** @var MailSending $sending */
        $sending = $state['events']->events[0];
        /** @var MailSent $sent */
        $sent = $state['events']->events[1];
        $this->assertSame([
            'to' => ['to@example.com', 'to@example.com'],
            'subject' => '',
        ], $sending->jsonSerialize());

        $sentJson = $sent->jsonSerialize();
        $this->assertSame(['to@example.com', 'to@example.com'], $sentJson['to']);
        $this->assertSame('', $sentJson['subject']);
        $this->assertIsFloat($sentJson['elapsed']);

        $type = $state['pool']->acquireCalls[0]['type'];
        $this->assertSame('default', $type);
        $this->assertSame(0.5, $state['pool']->acquireCalls[0]['timeout']);

        $connection = $state['pool']->resources[spl_object_id($state['mailer']) . ':' . $type];
        $this->assertInstanceOf(SpyConnection::class, $connection);
        $this->assertSame(1, $connection->sendCount);
        $this->assertNotNull($connection->lastRequest);
        $this->assertSame('smtp.example.com', $connection->lastRequest->host);
        $this->assertSame(2525, $connection->lastRequest->port);
        $this->assertSame('mailer-user', $connection->lastRequest->username);
        $this->assertSame('mailer-pass', $connection->lastRequest->password);
        $this->assertSame('from@example.com', $connection->lastRequest->from);
        $this->assertSame(['to@example.com', 'cc@example.com', 'bcc@example.com'], $connection->lastRequest->recipients);
        $this->assertSame("Subject: test\r\n\r\nbody", $connection->lastRequest->payload);
        $this->assertTrue($state['serializer']->lastOmitBccInHeaders);
    }

    public function testSendDeduplicatesRecipientsByNormalizedEmailButKeepsFirstOriginalValue(): void
    {
        $state = $this->makeMailer([
            'host' => 'smtp.example.com',
            'port' => 25,
            'encryption' => 'none',
            'username' => '',
            'password' => '',
            'from' => 'sender@example.com',
            'from_name' => null,
            'timeout' => 3.5,
            'pool_size' => 1,
            'pool_timeout' => 0.5,
        ]);

        $message = Message::compose()
            ->from(new Address('from@example.com'))
            ->to(new Address('Mark@example.com'))
            ->cc(new Address('mark@example.com'))
            ->bcc(new Address('MARK@example.com'))
            ->to(new Address('other@example.com'))
            ->get();

        $state['mailer']->send($message);

        $type = $state['pool']->acquireCalls[0]['type'];
        $connection = $state['pool']->resources[spl_object_id($state['mailer']) . ':' . $type];
        $this->assertInstanceOf(SpyConnection::class, $connection);
        $this->assertSame(['Mark@example.com', 'other@example.com'], $connection->lastRequest->recipients);
    }

    public function testComposePrefillsConfiguredFromAddress(): void
    {
        $mailer = $this->makeMailer([
            'host' => 'smtp.example.com',
            'port' => 25,
            'encryption' => 'none',
            'username' => '',
            'password' => '',
            'from' => 'sender@example.com',
            'from_name' => 'Sender',
            'timeout' => 1.0,
            'pool_size' => 1,
            'pool_timeout' => 1.0,
        ])['mailer'];

        $message = $mailer->compose();
        $from = $message->getFrom();

        $this->assertSame('sender@example.com', $from->getEmail());
        $this->assertSame('Sender', $from->getName());
    }

    public function testComposeKeepsNullFromNameWhenUnset(): void
    {
        $mailer = $this->makeMailer([
            'host' => 'smtp.example.com',
            'port' => 25,
            'encryption' => 'none',
            'username' => '',
            'password' => '',
            'from' => 'sender@example.com',
            'from_name' => null,
            'timeout' => 1.0,
            'pool_size' => 1,
            'pool_timeout' => 1.0,
        ])['mailer'];

        $message = $mailer->compose();
        $from = $message->getFrom();

        $this->assertSame('sender@example.com', $from->getEmail());
        $this->assertNull($from->getName());
    }

    public function testComposeTrimsConfiguredFromAddressWhitespace(): void
    {
        $mailer = $this->makeMailer([
            'host' => 'smtp.example.com',
            'port' => 25,
            'encryption' => 'none',
            'username' => '',
            'password' => '',
            'from' => '  sender@example.com  ',
            'from_name' => 'Sender',
            'timeout' => 1.0,
            'pool_size' => 1,
            'pool_timeout' => 1.0,
        ])['mailer'];

        $message = $mailer->compose();

        $this->assertSame('sender@example.com', $message->getFrom()->getEmail());
        $this->assertSame('Sender', $message->getFrom()->getName());
    }

    public function testComposePreservesConfiguredFromNameWhitespace(): void
    {
        $mailer = $this->makeMailer([
            'host' => 'smtp.example.com',
            'port' => 25,
            'encryption' => 'none',
            'username' => '',
            'password' => '',
            'from' => 'sender@example.com',
            'from_name' => '  Sender  ',
            'timeout' => 1.0,
            'pool_size' => 1,
            'pool_timeout' => 1.0,
        ])['mailer'];

        $message = $mailer->compose();

        $this->assertSame('  Sender  ', $message->getFrom()->getName());
    }

    public function testSendDispatchesFailedEventWhenDeliveryThrows(): void
    {
        $eventDispatcher = new SpyEventDispatcher();

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('microtime')->willReturn(1_000.0);
        $clock->method('elapsed')->willReturn(0.001);

        $mailer = new class ($eventDispatcher, $clock) extends Mailer {
            public function __construct(protected SpyEventDispatcher $spy, ClockInterface $clock)
            {
                $this->eventDispatcher = $spy;
                $this->clock = $clock;
            }

            protected function deliver(MessageInterface $message): void
            {
                throw new RuntimeException('delivery boom');
            }
        };

        $message = Message::compose()
            ->from(new Address('from@example.com'))
            ->to(new Address('to@example.com'))
            ->cc(new Address('cc@example.com'))
            ->bcc(new Address('bcc@example.com'))
            ->get();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('delivery boom');

        try {
            $mailer->send($message);
        } finally {
            $this->assertCount(2, $eventDispatcher->events);
            $this->assertInstanceOf(MailSending::class, $eventDispatcher->events[0]);
            $this->assertInstanceOf(MailFailed::class, $eventDispatcher->events[1]);
            /** @var MailFailed $failed */
            $failed = $eventDispatcher->events[1];
            $failedJson = $failed->jsonSerialize();
            $this->assertSame(['to@example.com'], $failedJson['to']);
            $this->assertSame('', $failedJson['subject']);
            $this->assertIsFloat($failedJson['elapsed']);
            $this->assertFalse($eventDispatcher->events[1] instanceof MailSent);
        }
    }

    public function testConstructorSeedsConnectionPoolWithConfiguredSize(): void
    {
        $state = $this->makeMailer([
            'pool_size' => 3,
        ]);

        $this->assertCount(1, $state['pool']->addCalls);
        $this->assertSame(3, $state['pool']->addCalls[0]['size']);
        $this->assertSame('default', $state['pool']->addCalls[0]['type']);
    }

    public function testInvalidEncryptionValueRaisesConfigurationException(): void
    {
        $mailer = $this->makeMailer([
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'invalid-mode',
            'username' => '',
            'password' => '',
            'from' => 'from@example.com',
            'timeout' => 5.0,
            'pool_size' => 1,
            'pool_timeout' => 1.0,
        ])['mailer'];

        $message = Message::compose()
            ->from(new Address('from@example.com'))
            ->to(new Address('to@example.com'))
            ->text('body')
            ->get();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('SMTP encryption must be one of "none", "starttls", or "ssl"; got "invalid-mode".');

        $mailer->send($message);
    }

    public function testEmptyHostRaisesProtocolExceptionDuringDelivery(): void
    {
        $state = $this->makeMailer([
            'host' => '',
            'port' => 587,
            'encryption' => 'none',
            'username' => '',
            'password' => '',
            'from' => 'from@example.com',
            'timeout' => 1.0,
            'pool_size' => 1,
            'pool_timeout' => 1.0,
        ], new Connection());

        $mailer = $state['mailer'];

        $message = Message::compose()
            ->from(new Address('from@example.com'))
            ->to(new Address('to@example.com'))
            ->text('body')
            ->get();

        $this->expectException(ProtocolException::class);

        $mailer->send($message);
    }

    /**
     * @return array{mailer: Mailer, serializer: SpySerializer, pool: SpyPoolManager, events: SpyEventDispatcher}
     */
    protected function makeMailer(array $parameters = [], ?object $defaultResource = null): array
    {
        $serializer = new SpySerializer();
        $poolManager = new SpyPoolManager();
        $poolManager->defaultResource = $defaultResource ?? new SpyConnection();
        $eventDispatcher = new SpyEventDispatcher();

        /** @var Mailer $mailer */
        $mailer = $this->make(Mailer::class, array_merge([
            'eventDispatcher' => $eventDispatcher,
            'serializer' => $serializer,
            'poolManager' => $poolManager,
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'starttls',
            'username' => '',
            'password' => '',
            'from' => 'from@example.com',
            'from_name' => null,
            'timeout' => 10.0,
            'pool_size' => 1,
            'pool_timeout' => 1.0,
        ], $parameters));

        return [
            'mailer' => $mailer,
            'serializer' => $serializer,
            'pool' => $poolManager,
            'events' => $eventDispatcher,
        ];
    }

    protected function makeProbeMailer(array $parameters = []): MailerProbe
    {
        /** @var MailerProbe $mailer */
        $mailer = $this->make(MailerProbe::class, array_merge([
            'host' => 'smtp.example.com',
            'username' => '',
            'password' => '',
            'from' => 'from@example.com',
        ], $parameters));

        return $mailer;
    }
}
