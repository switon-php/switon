# Design Rationale {#design-rationale}
`switon/mail` keeps outbound mail behind one application boundary: build a message, hand it to a mailer, and let transport stay inside the package.

## See also {#see-also}

- [Mail Package](README.md) — public send path and configuration
- [Mail FAQ](faq.md#mail-faq) — short answers for setup and failures

## Core Problem {#core-problem}

Mail code tends to drift into transport details.

- one service builds SMTP payloads directly
- another repeats MIME rules
- a third owns connection reuse
- failures become raw transport errors

The package standardizes that work so application code keeps one stable mail boundary.

## Primary Goal {#primary-goal}

Switon mail makes the send path predictable.

- `MailerInterface` is the app-facing boundary
- `Message` holds mail intent before transport details
- `Rfc822SerializerInterface` turns that intent into RFC 5322 and MIME
- `ConnectionInterface` keeps SMTP delivery and handle reuse behind the boundary

That gives apps one place to send mail while leaving transport policy and pooling inside the component.

## Core Choice {#core-choice}

The package separates message structure, serialization, and delivery.

- the message object describes sender, recipients, headers, and bodies
- the serializer owns RFC 5322 details such as multipart output and dot-stuffing
- the connection owns SMTP transport
- the mailer ties those pieces together and emits lifecycle events

This keeps common mail behavior easy to test without exposing curl or SMTP protocol work to business code.

## Trade-Off {#trade-off}

Switon prefers one clear mail boundary over transport-specific control in application services.

- **Benefit:** message code stays readable and testable.
- **Benefit:** pooling and transport reuse stay centralized.
- **Cost:** lower-level SMTP tuning belongs in configuration or component code.

## Deliberate Simplicity {#deliberate-simplicity}

The package does not try to own adjacent concerns.

- it does not render templates
- it does not decide retry or queue policy
- it does not expose SMTP commands to callers

That boundary keeps the package focused on outbound delivery, not on the rest of the mail pipeline.
