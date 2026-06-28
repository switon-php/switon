<?php

declare(strict_types=1);

namespace Switon\Mail;

use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Mail\Event\MailFailed;
use Switon\Mail\Event\MailSending;
use Switon\Mail\Event\MailSent;
use Switon\Mail\Exception\ConfigurationException;
use Switon\Mail\Exception\InvalidMessageException;
use Switon\Pooling\PoolManagerInterface;
use Throwable;

use function microtime;
use function strtolower;
use function trim;

/**
 * Default SMTP mailer for outbound mail delivery.
 *
 * Use when application code should send outbound mail through the package default SMTP flow.
 *
 * Guidance: Required services are injected via <code>#[Autowired]</code> before <code>__construct()</code>; do not add
 * <code>isset</code>/<code>null</code> checks for mandatory dependencies in this class.
 *
 * Road-signs:
 * - validate sender + recipients
 * - dispatch MailSending / MailSent / MailFailed
 * - serialize RFC 5322 + dot-stuff
 * - borrow Connection via PoolManagerInterface
 * - DeliveryException leaves
 *
 * @see \Switon\Core\Attribute\Autowired
 * @see \Switon\Mail\MailerInterface
 * @see \Switon\Mail\MessageInterface
 * @see \Switon\Mail\Event\MailSending
 * @see \Switon\Mail\Event\MailSent
 * @see \Switon\Mail\Event\MailFailed
 * @see \Switon\Mail\Rfc822SerializerInterface
 * @see \Switon\Mail\ConnectionInterface
 * @see \Switon\Pooling\PoolManagerInterface
 * @see \Switon\Di\Container::createInstance()
 * @see \Switon\Mail\Exception\InvalidMessageException
 * @see \Switon\Mail\Exception\DeliveryException
 */
class Mailer implements MailerInterface
{
    #[Autowired] protected EventDispatcherInterface $eventDispatcher; // @phpstan-ignore property.uninitialized

    #[Autowired] protected Rfc822SerializerInterface $serializer; // @phpstan-ignore property.uninitialized

    /** @var PoolManagerInterface<Mailer, ConnectionInterface> */
    #[Autowired] protected PoolManagerInterface $poolManager; // @phpstan-ignore property.uninitialized

    #[Autowired] protected string $host;

    #[Autowired] protected int $port = 587;

    /** @var 'none'|'starttls'|'ssl' */
    #[Autowired] protected string $encryption = 'starttls';

    #[Autowired] protected string $username; // @phpstan-ignore property.uninitialized

    #[Autowired] protected string $password; // @phpstan-ignore property.uninitialized

    #[Autowired] protected string $from; // @phpstan-ignore property.uninitialized

    #[Autowired] protected ?string $from_name = null;

    #[Autowired] protected float $timeout = 10.0;

    #[Autowired] protected int $pool_size = 1;

    #[Autowired] protected float $pool_timeout = 1.0;

    public function __construct()
    {
        $this->poolManager->add($this, [ConnectionInterface::class, ['owner' => $this]], $this->pool_size);
    }

    /**
     * Sends one message through the default SMTP delivery pipeline and dispatches lifecycle events around it.
     */
    public function send(MessageInterface $message): void
    {
        if ($message->getTo() === [] && $message->getCc() === [] && $message->getBcc() === []) {
            InvalidMessageException::raise('Mail message has no recipients (To/Cc/Bcc).');
        }

        $this->eventDispatcher->dispatch(new MailSending($this, $message));
        $start = microtime(true);

        try {
            $this->deliver($message);
            $this->eventDispatcher->dispatch(new MailSent($this, $message, microtime(true) - $start));
        } catch (Throwable $exception) {
            $this->eventDispatcher->dispatch(new MailFailed($this, $message, $exception, microtime(true) - $start));
            throw $exception;
        }
    }

    /**
     * Creates a new message pre-filled with the configured default sender identity.
     */
    public function compose(): Message
    {
        return Message::compose()->from(new Address(trim($this->from), $this->from_name));
    }

    /**
     * Serializes the message, builds a delivery request, and submits it through a pooled SMTP connection.
     */
    protected function deliver(MessageInterface $message): void
    {
        $payload = $this->serializer->serialize($message, true);
        $payload = $this->serializer->dotStuff($payload);

        $delivery = $this->createDelivery($message, $payload);
        $connection = $this->poolManager->guard($this, $this->pool_timeout);
        $connection->send($delivery);
    }

    /**
     * Builds the immutable SMTP delivery request from the validated message and serialized payload.
     */
    protected function createDelivery(MessageInterface $message, string $payload): Delivery
    {
        $encryption = Encryption::tryFrom(strtolower(trim($this->encryption)));
        if ($encryption === null) {
            ConfigurationException::raise(
                'SMTP encryption must be one of "none", "starttls", or "ssl"; got "{value}".',
                ['value' => $this->encryption]
            );
        }

        return new Delivery(
            host: trim($this->host),
            port: $this->port,
            encryption: $encryption,
            username: $this->username,
            password: $this->password,
            timeout: $this->timeout,
            from: $message->getFrom()->getEmail(),
            recipients: $this->allRecipients($message),
            payload: $payload,
        );
    }

    /**
     * Returns the de-duplicated recipient email list across To, Cc, and Bcc.
     *
     * @return list<string>
     */
    protected function allRecipients(MessageInterface $message): array
    {
        $seen = [];
        $out = [];
        foreach ([$message->getTo(), $message->getCc(), $message->getBcc()] as $list) {
            foreach ($list as $address) {
                $email = $address->getEmail();
                $normalized = strtolower(trim($email));
                if (!isset($seen[$normalized])) {
                    $seen[$normalized] = true;
                    $out[] = $email;
                }
            }
        }

        return $out;
    }
}
