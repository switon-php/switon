# AI Tools

Use tools when a CLI command is safe and useful for AI-assisted discovery or diagnostics.

## When to expose a tool

- The command is read-only or has explicit, narrow effects.
- The output is structured enough for an AI agent to act on.
- The command answers a recurring framework or application question.

Avoid exposing broad destructive commands as tools. Prefer diagnostics, descriptions, listings, dry runs, and targeted
checks.

## Command shape

Mark the command or action with `Switon\Command\Attribute\Tool`:

```php
use Switon\Command\Attribute\Tool;

final class HealthCommand
{
    #[Tool('Check application health signals')]
    public function checkAction(): array
    {
        return ['ok' => true];
    }
}
```

Keep tool output stable. Use arrays or simple scalar fields instead of prose-only terminal text when another agent must
parse the result.

## Verification

After adding or changing a tool, verify the command surface:

```bash
bash app-admin/bin/console tool:list
bash app-admin/bin/console tool:description <tool-name>
```

Use `list` for human CLI discovery and `tool:list` for AI-facing discovery. If a command is intentionally hidden from
ordinary `list`, verify that the tool metadata still appears where expected.
