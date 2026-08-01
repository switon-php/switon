# Switon Queue Package

[![CI](https://img.shields.io/github/actions/workflow/status/switon-php/queue/ci.yml?branch=main&label=CI)](https://github.com/switon-php/queue/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's Redis-backed queue transport for named queues, job discovery, delayed delivery, and worker/CLI execution.

## Highlights

- **Named queues:** push, pull, consume, and delay all use logical queue names.
- **Envelope metadata:** each job keeps its body, headers, message id, queue name, and timestamp together.
- **Job discovery:** `#[Queued]` binds runner jobs to logical queues.
- **Delayed delivery:** delayed jobs can be surfaced back into the active queue.
- **Worker and stats flow:** CLI commands cover `queue:stats`, `queue:push`, `queue:run`, `queue:work`, and
  `queue:migrate-due`.

## Installation

```bash
composer require switon/queue
```

## Quick Start

```php
use Switon\Core\Attribute\Autowired;
use Switon\Core\RunnerInterface;
use Switon\Queue\Attribute\Queued;
use Switon\Queue\QueueEnvelope;
use Switon\Queue\QueueInterface;

class UserService
{
    #[Autowired] protected QueueInterface $queue;

    public function register(int $userId): void
    {
        $this->queue->push('emails', QueueEnvelope::of(['userId' => $userId]));
    }
}

#[Queued('emails')]
class SendWelcomeEmail implements RunnerInterface
{
    /**
     * @param QueueEnvelope $payload
     */
    public function run(mixed $payload): mixed
    {
        $userId = $payload->body['userId'] ?? null;

        return null;
    }
}
```

Docs: https://github.com/switon-php/queue/blob/main/docs/README.md

## License

MIT.
