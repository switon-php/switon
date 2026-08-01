# Mail FAQ {#mail-faq}
Short answers for message structure, SMTP configuration, and delivery failures.

> **Prerequisites:** [Mail Package](README.md)

## Getting Started {#getting-started}

### How do I send an email? {#send-mail}

Inject `Switon\Mail\MailerInterface`, call `compose()`, add recipients, and call `send()`. See [Quick Start](README.md#quick-start).

### When should I use `MailerInterface::compose()` instead of `Message::compose()`? {#compose-choice}

Use `MailerInterface::compose()` when the configured default sender is correct. Use `Switon\Mail\Message::compose()` when one message needs a different sender.

### What is required before `send()`? {#required-fields}

`from()` and at least one recipient in `to()`, `cc()`, or `bcc()`. Subject and body are optional.

### Can I send only `bcc()` recipients? {#bcc-only}

Yes. `bcc()` alone is valid as long as `from()` is set. The SMTP envelope still includes the recipients; the DATA section uses `To: undisclosed-recipients:;` when there are no visible `To` or `Cc`.

### Can I send text and HTML together? {#html-and-text}

Yes. Set both `text()` and `html()`; the serializer builds `multipart/alternative` output.

### How do I add attachments? {#attachments}

Call `attach()` with a `Switon\Mail\AttachmentInterface` value. Any attachment turns the message into `multipart/mixed` output.

## Configuration {#configuration}

### Where do I configure SMTP? {#where-configure}

Bind `Switon\Mail\MailerInterface` in `switon.yml`. See [Configuration](README.md#configuration).

### Which encryption values are valid? {#valid-encryption}

`none`, `starttls`, or `ssl`.

### Do I always need SMTP AUTH credentials? {#smtp-auth}

No. An empty `username` skips AUTH.

### How do I inspect the current option surface? {#discover-options}

Run `bash bin/console config:options 'Switon\Mail\MailerInterface'`.

## Delivery {#delivery}

### Why do Bcc recipients not appear in the message headers? {#bcc-headers}

That is the normal send path. Bcc stays in the SMTP envelope and stays out of DATA headers.

### Which exception should I catch first? {#which-exception}

Catch `Switon\Mail\Exception\InvalidMessageException` for message-structure problems and `Switon\Mail\Exception\DeliveryException` for SMTP failures. Leaf delivery failures include `ConfigurationException`, `ConnectionException`, `TlsException`, `AuthException`, and `ProtocolException`.

### Does the package emit events around send? {#mail-events}

Yes: `Switon\Mail\Event\MailSending` fires before delivery, `Switon\Mail\Event\MailSent` fires on success, and `Switon\Mail\Event\MailFailed` fires before the original exception is rethrown.

## Troubleshooting {#troubleshooting}

### Send fails with an encryption error {#encryption-error}

Check `encryption` first, then match the port to it (typically `587/starttls` or `465/ssl`).

### Why is my custom header missing? {#missing-header}

Reserved SMTP header names such as `From`, `To`, `Subject`, and `MIME-Version` are skipped by the serializer.

### Mail send is slow during bursts {#burst-slowdown}

Increase `pool_size` before stretching `pool_timeout`.

### I need retries or deferred sending. Is this package enough? {#retries-and-background}

No. Keep `mail` as the SMTP layer and pair it with `queue` or `schedule`.

### Why does `compose()` fail even with a configured `from`? {#compose-from}

`compose()` needs a non-empty sender. A blank string still produces a message without a usable `from`, and `InvalidMessageException` is raised when `send()` runs.
