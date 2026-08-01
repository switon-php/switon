# Switon Mail Package

[![Mail CI](https://img.shields.io/github/actions/workflow/status/switon-php/mail/ci.yml?branch=main&label=Mail%20CI)](https://github.com/switon-php/mail/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's mailer for composing outbound messages, delivering them through pooled SMTP connections, and surfacing delivery
events.

## Highlights

- **Message composition:** `MailerInterface::compose()` builds a ready-to-send message object.
- **Delivery flow:** recipients are validated before the message is sent.
- **SMTP transport:** repeated delivery can reuse a pooled connection.
- **Delivery visibility:** mail send, success, and failure states can be observed.
- **Transport-ready messages:** composed messages can be sent directly.

## Installation

```bash
composer require switon/mail
```

## Quick Start

```php
use Switon\Core\Attribute\Autowired;
use Switon\Mail\Address;
use Switon\Mail\MailerInterface;
use Switon\Mail\Message;

class NotifyService
{
    #[Autowired] protected MailerInterface $mailer;

    public function ping(string $to): void
    {
        $msg = Message::compose()
            ->from(new Address('app@example.com'))
            ->to(new Address($to))
            ->subject('Ping')
            ->text('Hello.');

        $this->mailer->send($msg->get());
    }
}
```

Docs: https://github.com/switon-php/mail/blob/main/docs/README.md

## License

MIT.
