# Validation constant constraints {#validation-constant-constraints}

Use this guide when you need class-constant backed validation with `Switon\Validating\Attribute\ConstantValue`.

## Usage patterns {#usage-patterns}

Use `Switon\Validating\Attribute\ConstantValue` for class-constant based validation.
The application input and constants class names below are illustrative.

```php
use Switon\Validating\Attribute\ConstantValue;

final class CreateTicketInput
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    #[ConstantValue]
    public string $status;
}
```

By default, `ConstantValue` uses the validated field name as the constant prefix:

- for `$status`, it checks constants named `STATUS_*`
- the constants live on the current input object unless you override the class

If the constants live on another class, pass `class`:

```php
use App\Domain\TicketStatus;
use Switon\Validating\Attribute\ConstantValue;

final class CreateTicketInput
{
    #[ConstantValue(class: TicketStatus::class)]
    public string $status;
}
```

If the field name and constant prefix differ, pass `prefix`:

```php
use Switon\Validating\Attribute\ConstantValue;

final class CreateTicketInput
{
    public const TYPE_BUG = 'bug';
    public const TYPE_TASK = 'task';

    #[ConstantValue(prefix: 'type')]
    public string $kind;
}
```

## Next {#next}

- **To choose between enum and constant validation:** [Validation Package](https://github.com/switon-php/validation/blob/main/docs/README.md#built-in-constraints)
- **To validate HTTP input classes:** [Typed input binding](https://github.com/switon-php/http/blob/main/docs/typed-input-binding.md#typed-input-binding)
