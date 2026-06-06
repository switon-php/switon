# Switon Schedule Package

[![Schedule CI](https://img.shields.io/github/actions/workflow/status/switon-php/schedule/ci.yml?branch=main&label=Schedule%20CI)](https://github.com/switon-php/schedule/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's scheduled task layer for `#[Scheduled]` runners, task discovery, locking, and CLI execution.

## Highlights

- **Task registration:** `#[Scheduled]` declares scheduled work on runner classes.
- **Automatic discovery:** app task paths and package metadata are scanned.
- **Task execution:** `TaskRunner` matches schedules and dispatches tasks.
- **Distributed locking:** Redis-backed exclusivity is available across replicas.
- **CLI control:** `ScheduleCommand` covers work, run, list, and task inspection.

## Installation

```bash
composer require switon/schedule
```

## Quick Start

```php
use Switon\Core\RunnerInterface;
use Switon\Schedule\Attribute\Scheduled;

#[Scheduled('0 * * *', lockTtl: 60)]
final class PurgeStaleSessionsTask implements RunnerInterface
{
    public function run(mixed $payload): mixed
    {
        return null;
    }
}
```

```bash
bash bin/console schedule:list
bash bin/console schedule:run
```

Docs: https://docs.switon.dev/latest/schedule

## License

MIT.
