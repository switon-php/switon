# Mail Package {#mail-package}
`switon/mail` keeps outbound mail behind one application boundary: build a message, hand it to a mailer, and let SMTP and connection reuse stay inside the package. Choose it when application code should send mail without touching SMTP commands or pooled connections directly. Templating, retry, and queueing stay outside.

**Key features:**

- **One send boundary:** inject `Switon\Mail\MailerInterface` and keep SMTP details out of application code.
- **Fluent message model:** `Switon\Mail\Message` covers sender, recipients, bodies, attachments, headers, and Message-ID.
- **SMTP-ready output:** `Switon\Mail\Rfc822SerializerInterface` builds RFC 5322 and MIME payloads and applies dot-stuffing.
- **Observable delivery:** the send path emits events for start, success, and failure.
- **Pooled transport:** the default `Switon\Mail\Mailer` reuses `Switon\Mail\ConnectionInterface` instances through `Switon\Pooling\PoolManagerInterface`.

## Installation {#installation}

**Requirements:** PHP 8.3+, `ext-curl`, `switon/core`, `switon/event`, `switon/pool`, `psr/event-dispatcher`

```bash
composer require switon/mail
```

## Quick Start {#quick-start}

Bind `Switon\Mail\MailerInterface`, inject it, and send one message.

```yaml
# switon.yml
Switon\Mail\MailerInterface:
  class: Switon\Mail\Mailer
  host: smtp.example.com
  port: 587
  encryption: starttls
  username: ''
  password: ''
  from: no-reply@example.com
  from_name: Switon
  timeout: 10
  pool_size: 1
  pool_timeout: 1
```

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Mail\Address;
use Switon\Mail\MailerInterface;

class NotifyService
{
    #[Autowired] protected MailerInterface $mailer;

    public function sendWelcome(string $email): void
    {
        $message = $this->mailer->compose()
            ->to(new Address($email))
            ->subject('Welcome')
            ->text('Thanks for signing up.');

        $this->mailer->send($message);
    }
}
```

`MailerInterface::compose()` pre-fills the configured sender. Use `Switon\Mail\Message::compose()` directly when one message needs a different sender.

## Core Concepts {#core-concepts}

### Send Boundary {#send-boundary}

`MailerInterface` is the application boundary for sending mail. Application services depend on this interface and stay away from SMTP commands and pooled connections.

### Message Model {#message-model}

`Switon\Mail\Message` is the outbound mail builder.

- `from()` is required before send
- `to()`, `cc()`, and `bcc()` append recipients
- at least one recipient across `to`/`cc`/`bcc` is required; subject and body are optional
- `text()` and `html()` can be used together (the serializer builds `multipart/alternative`)
- `attach()` adds MIME attachments and turns the message into `multipart/mixed`
- `replyTo()`, `header()`, and `messageId()` are optional
- reserved SMTP header names such as `From`, `To`, `Subject`, and `MIME-Version` are skipped during serialization
- `Bcc` recipients stay in the SMTP envelope and stay out of DATA headers

### Send Path And Events {#events}

`Switon\Mail\Mailer::send()` validates recipients, builds the RFC 5322 payload, borrows a `Switon\Mail\ConnectionInterface` from the pool, and delivers. The send path emits three events:

- `Switon\Mail\Event\MailSending` — before delivery starts
- `Switon\Mail\Event\MailSent` — on successful delivery
- `Switon\Mail\Event\MailFailed` — on failure, before the original exception is rethrown

### Failures {#failures}

- `Switon\Mail\Exception\InvalidMessageException` is the umbrella for message-structure problems.
- `Switon\Mail\Exception\DeliveryException` is the umbrella for delivery-time failures; leaf cases include `ConfigurationException`, `ConnectionException`, `TlsException`, `AuthException`, and `ProtocolException`.

## Configuration {#configuration}

Most applications only need to set:

- `host` and `port` — SMTP endpoint
- `encryption` — `none`, `starttls`, or `ssl`
- `from` — default sender used by `MailerInterface::compose()`
- `pool_size` — number of pooled connections kept for this mailer

Common pairings: `587/starttls`, `465/ssl`, or `none` for a trusted relay.

### Option Discovery {#option-discovery}

```bash
bash bin/console config:options 'Switon\Mail\MailerInterface'
```

## Keep in Mind {#keep-in-mind}

- `MailerInterface::compose()` only fills the sender; recipients are still your job.
- `Bcc` stays hidden in normal send output.
- `pool_size` is the first knob to turn when bursts make the pool feel tight.
- Keep `from` non-empty if you expect `compose()` to work out of the box.

## Next {#next}

- **To read short answers:** [FAQ](faq.md#mail-faq)
- **To understand the trade-offs:** [Design Rationale](design.md#design-rationale)
- **To tune connection reuse:** [Pool Package](../pool/README.md)
- **To make mail asynchronous or retryable:** [Queue Package](../queue/README.md)
- **To feed delivery lifecycle into logs or side effects:** [Event Package](../event/README.md)
