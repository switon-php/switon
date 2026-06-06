# Send transactional email {#send-transactional-email}

Use this guide when one working business event should now send welcome, reset, receipt, or approval mail without turning SMTP details, queue timing, or template rendering into controller logic too early.

## Choose the first mail-delivery boundary {#delivery-boundary}

Start with the boundary that matches the real delivery problem.

| Boundary | Best when | Keep in mind |
|---|---|---|
| [Mail](../mail/README.md) | one application service can send immediately after the business action succeeds | strongest default for the first welcome, receipt, or approval email when request latency is acceptable |
| [Queue](../queue/README.md) | SMTP latency, retries, or process separation matter more than immediate completion | use this when the user-facing request should finish without waiting on SMTP; keep the mail service the same and change only the producer-to-worker handoff |
| [Schedule](../schedule/README.md) | the email should start from a time rule, such as reminder or follow-up delivery | schedule decides **when** to trigger work; it does not replace the mail send boundary |
| [Add authentication](add-authentication.md#add-authentication) | the email comes from signup, login recovery, or protected account flows | keep identity, token, and access rules separate from the outbound-mail boundary |

One common application shape is:

- **Application service** to decide that one business event should notify the user
- **Template renderer** to build subject, text, and HTML from app data
- **Mail** to compose and send the message
- **Queue** only when delivery should leave the request path
- **Schedule** only when time, not the original event, should trigger the email

## Recommended build order {#build-order}

### 1. Start from one business event you can name clearly {#build-event}

Pick one event such as `user signed up`, `password reset requested`, or `order paid`.
The first mail path should explain *why the email exists* in one sentence before you discuss transport.

### 2. Keep one application mail service between the event and SMTP {#build-service}

Do not let controllers, queue jobs, or scheduled tasks build email ad hoc.
Keep one service such as `WelcomeEmailServiceInterface` or `ReceiptMailerInterface` so the same mail intent can run inline, in a worker, or on a time rule.

### 3. Render the message from app data before you compose transport details {#build-render}

Template rendering should turn domain data into `subject`, `text`, and optional `html`.
Then let `MailerInterface` turn that rendered result into a `Message`.

This keeps link generation, token choice, and copywriting in application code instead of scattering them across SMTP calls.

### 4. Choose inline send only when the failure window matches the user experience {#build-vs-queue}

Keep delivery in-request when all three are true:
- the user can wait for the SMTP round trip
- a send failure should fail the same request or command
- you do not need durable handoff yet

Move to [Queue](../queue/README.md) when signup, checkout, or other user-facing paths should complete without waiting on SMTP success.

### 5. Add one visible success path and one visible failure path {#build-visibility}

Before you scale delivery, confirm two things:
- one real or test path reaches `MailSent`
- one bad configuration or delivery failure reaches a visible log or failure surface

If you are not observing `MailSent` yet, add one small listener or log hook first. See [Mail events](../mail/README.md#events), [Mail failures](../mail/README.md#failures), [Event](../event/README.md), and [Log](../log/README.md#quick-start).

The first trustworthy mail path needs observability before it needs multiple providers.

### 6. Add reminders or follow-ups as a separate time-based boundary {#build-schedule}

If the email should go out tomorrow, every week, or after an expiry window, keep that trigger in [Schedule](../schedule/README.md) or [Add recurring work](add-recurring-work.md#add-recurring-work).
Do not overload the original signup or checkout request with future-delivery logic.

### 7. Stop before campaign or provider-platform design {#build-stop}

The first transactional-email win does **not** require bulk audience logic, provider failover, or marketing automation.
Make one business-triggered mail path trustworthy before you widen the system.

## Minimal wiring {#minimal-wiring}

Use short code only to show the seam between app data, rendering, and delivery.

Application-level names like `WelcomeEmailServiceInterface`, `WelcomeEmailTemplateInterface`, and `RegistrationService` below are illustrative. The stable part is the Switon mail boundary and the optional queue/schedule handoff around it.

### Keep the mailer config small and explicit {#wiring-config}

Start with the normal SMTP boundary in `switon.yml`:

```yaml
# switon.yml
Switon\Mail\MailerInterface:
  class: Switon\Mail\Mailer
  host: smtp.example.com
  port: 587
  encryption: starttls
  username: ${MAIL_USERNAME:-}
  password: ${MAIL_PASSWORD:-}
  from: no-reply@example.com
  from_name: Example App
```

Use the component guide for deeper SMTP or pool tuning: [Mail](../mail/README.md#quick-start).

### Keep rendering and sending behind one application service {#wiring-service}

Define one app-owned boundary for the business email:

```php
namespace App\Service;

interface WelcomeEmailServiceInterface
{
    public function sendForUser(int $userId): void;
}
```

Keep template rendering separate from transport composition:

```php
namespace App\Mail;

use App\Entity\User;

interface WelcomeEmailTemplateInterface
{
    /** @return array{subject:string,text:string,html:string} */
    public function render(User $user): array;
}
```

Then let one service load app data, render the content, and send the message:

```php
namespace App\Service;

use App\Mail\WelcomeEmailTemplateInterface;
use App\Repository\UserRepository;
use Switon\Core\Attribute\Autowired;
use Switon\Mail\Address;
use Switon\Mail\MailerInterface;

final class WelcomeEmailService implements WelcomeEmailServiceInterface
{
    #[Autowired] protected UserRepository $userRepository;
    #[Autowired] protected WelcomeEmailTemplateInterface $template;
    #[Autowired] protected MailerInterface $mailer;

    public function sendForUser(int $userId): void
    {
        $user = $this->userRepository->find($userId);

        if ($user === null) {
            return;
        }

        $body = $this->template->render($user);

        $message = $this->mailer->compose()
            ->to(new Address($user->email, $user->name))
            ->subject($body['subject'])
            ->text($body['text'])
            ->html($body['html']);

        $this->mailer->send($message);
    }
}
```

`Switon\Mail\Address` is the mail-package value object for one recipient address, so the `use Switon\Mail\Address;` import is part of the normal `switon/mail` API rather than an application-local helper.

This keeps responsibilities clear:

- the repository decides **which app data is loaded**
- the template decides **what the user should read**
- `MailerInterface` decides **how the message is delivered**

### Keep the first producer path thin {#wiring-inline}

When request latency and failure coupling are acceptable, call the mail service after the business action succeeds:

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;

final class RegistrationService
{
    #[Autowired] protected WelcomeEmailServiceInterface $welcomeEmail;

    public function completeSignup(int $userId): void
    {
        // persist the user first...
        $this->welcomeEmail->sendForUser($userId);
    }
}
```

This is the best first path when the app still needs to prove one clear mail outcome before adding workers.

### Move the same service behind queue transport when delivery should leave the request {#wiring-queue}

When signup or checkout should succeed without waiting on SMTP, keep the mail service and change only the handoff.
Here "producer-to-worker handoff" means the application service enqueues one mail job now, and a separate worker process sends the email later:

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Queue\QueueEnvelope;
use Switon\Queue\QueueInterface;

final class RegistrationService
{
    #[Autowired] protected QueueInterface $queue;

    public function completeSignup(int $userId): void
    {
        // persist the user first...
        $this->queue->push('welcome-emails', QueueEnvelope::of(['userId' => $userId]));
    }
}
```

`QueueEnvelope::of([...])` is a small factory method from `switon/queue`. It creates the envelope and fills its `body` payload from the array you pass in; queue name, message id, and timestamp are added by queue transport behavior later.

```php
namespace App\Job;

use App\Service\WelcomeEmailServiceInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\RunnerInterface;
use Switon\Queue\Attribute\Queued;
use Switon\Queue\QueueEnvelope;

#[Queued('welcome-emails')]
final class SendWelcomeEmailJob implements RunnerInterface
{
    #[Autowired] protected WelcomeEmailServiceInterface $welcomeEmail;

    public function run(mixed $payload): mixed
    {
        /** @var QueueEnvelope $envelope */
        $envelope = $payload;

        $this->welcomeEmail->sendForUser((int) ($envelope->body['userId'] ?? 0));

        return null;
    }
}
```

That keeps the application boundary stable:

- the producer decides **when one business event becomes mail work**
- the queue decides **how the work is handed off durably**
- the same mail service still decides **what gets sent**

For this queue-backed variant, do not stop at the job class alone. You also need the Redis-backed queue setup from [Add durable background work](add-durable-background-work.md#add-durable-background-work), especially `Switon\Redis\ClientInterface`, `Switon\Queue\QueueInterface`, and job discovery paths in `switon.yml`.

### Add time-based reminders outside the original event path {#wiring-schedule}

For follow-ups or reminders, keep the same mail service and wrap it in a scheduled task instead of embedding time logic in the original request.
Use [Add recurring work](add-recurring-work.md#add-recurring-work) when the trigger is now the clock rather than the original business event.

## What to avoid first {#pitfalls}

Keep the first transactional-email path intentionally narrow.

Avoid these too early:

- **Rendering mail bodies inside controllers, jobs, or tasks** — keep one app-owned render boundary before `MailerInterface`
- **Coupling business success to SMTP by accident** — if request latency or delivery failure should not block the user path, move to [Queue](../queue/README.md)
- **Letting `mail` choose your token or link model** — reset tokens, signed URLs, and account policy belong in application code
- **Treating scheduled reminders as delayed request logic** — use [Schedule](../schedule/README.md) when time is the real trigger
- **Designing multi-provider failover, campaigns, or analytics before one mail path is trustworthy** — make one welcome, reset, or receipt path observable first
- **Ignoring the failure split between invalid messages and delivery problems** — caller mistakes and SMTP/runtime failures are different boundaries

A beginner should feel this after finishing the first transactional-email path:

> I know where business-triggered email belongs, how content is rendered, and when delivery should stay inline or move to background work.

## Go deeper {#go-deeper}

- **To send this from signup, recovery, or protected account flows:** [Add authentication](add-authentication.md#add-authentication)
- **To keep delivery in the request and go deeper on SMTP, message building, and failures:** [Mail](../mail/README.md)
- **To hand the same mail path to durable workers:** [Add durable background work](add-durable-background-work.md#add-durable-background-work) · [Queue](../queue/README.md)
- **To trigger reminders or follow-ups on a time rule:** [Add recurring work](add-recurring-work.md#add-recurring-work) · [Schedule](../schedule/README.md)
- **To observe send lifecycle or failure hooks:** [Event](../event/README.md) · [Log](../log/README.md)
- **To protect the first mail-producing path before you widen it:** [Add testing to a working app](add-testing-to-a-working-app.md#add-testing-to-a-working-app)
