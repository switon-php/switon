# Dict Package {#dict-package}

`switon/dict` lets controlled value sets move through Switon as typed action parameters, validated input fields, and shared option rows.

**Key features:**

- **Typed action boundary:** use `OrderStatus $status` instead of repeating raw-scalar checks.
- **Property normalization:** use `#[DictValue(...)]` when an input object or entity field should store the resolved item value.
- **Shared read contracts:** `DictReaderInterface` serves type and item lookup; `OptionReaderInterface` serves option rows.
- **Type-owned codes:** `Dict::typeCode()` can be explicit or derived from the class name.
- **Default-item fallback:** missing values can resolve to the type default, while nullable parameters can still return `null`.

---

## Installation {#installation}

**Requirements:** PHP 8.3+

```bash
composer require switon/dict
```

Your app binds `Switon\Dict\DictReaderInterface` to one reader implementation. Bind `Switon\Dict\OptionReaderInterface` too when the same source also feeds UI options.
This package expects `switon/binding` for action-parameter resolution and `switon/validation` for `#[DictValue]` constraints.

---

## Quick Start {#quick-start}

Define one typed dict class:

```php
namespace App\Dict;

use Switon\Dict\Dict;

final class OrderStatus extends Dict
{
    public static function typeCode(): ?string
    {
        return 'order.status';
    }
}
```

Use it on an action parameter:

```php
namespace App\Controller;

use App\Dict\OrderStatus;

class OrderController
{
    public function updateAction(OrderStatus $status): void
    {
        // $status is a resolved dict item, not a raw string.
    }
}
```

Use the same type on a property when a scalar input field should normalize to the resolved item value:

```php
use App\Dict\OrderStatus;
use Switon\Dict\Attribute\DictValue;

final class UpdateOrderInput
{
    #[DictValue(OrderStatus::class)]
    public string $status;
}
```

If the same source also powers selects or radios, bind `Switon\Dict\OptionReaderInterface` to a reader that returns rows from the same backing data.

When the incoming value is invalid, resolution fails before the action body continues: action parameters raise `Switon\Dict\Exception\DictItemNotFoundException` (or a validator error when the validator runs first), and `#[DictValue]` raises the constraint's validation failure.

---

## Core Concepts {#core-concepts}

### Type-Owned Dicts {#type-owned-dicts}

A `Dict` subclass represents one controlled value set. The class name can define the type code, or `typeCode()` can return one explicitly.

By default, `App\Areas\Order\OrderStatus` and `App\Dict\OrderStatus` both derive `order.status`. Class names that do not produce a usable code must override `typeCode()`; an empty result raises `Switon\Dict\Exception\DictTypeCodeRequiredException`.

### Action Parameters {#action-parameters}

`Dict` is marked with `Switon\Binding\Attribute\ResolvedBy`, so action parameters typed as a `Dict` subclass resolve through `Switon\Dict\DictResolver`.

Resolution follows one simple path:

- read the dict type from `Dict::typeCode()` (or `Dict::class` derivation when `typeCode()` returns `null`)
- read the incoming value by parameter name through the scalar resolver
- return `null` when the parameter allows null and the value is missing or empty
- on a non-null parameter, fall back to `DictReaderInterface::getDefaultItem()` when the value is missing
- otherwise resolve through `DictReaderInterface::getItem()`, which matches the stored value (`DictEntry::itemValue`), not `itemCode`

### Field Normalization {#field-normalization}

`#[DictValue(...)]` belongs on entity or input properties.

- It validates scalar input against the same dict source.
- It normalizes accepted values to the stored item value.
- Use it when the field still arrives as a scalar, but the application wants dict-backed meaning.
- Unlike action parameters, `#[DictValue]` does not fall back to the default item on empty input.
- Nullable properties can still normalize to `null`.

### Option Rows {#option-rows}

`OptionReaderInterface::getOptions($typeCode, $builtin = true)` returns option rows for selects, radios, and tags.

The documented row shape is `array{label: string, value: string, code: string, tag: int, default: bool}`.

- `tag` is an `int` that the reader can use for sorting, grouping, or grouping labels.
- `default` marks the row that resolves when the action parameter is missing.
- The `$builtin` argument filters whether built-in dict data, app-owned data, or both should be returned; it is not part of the row shape.

---

## Configuration {#configuration}

Bind your reader in `switon.yml`:

```yaml
Switon\Dict\DictReaderInterface:
  class: App\Dict\ArrayDictReader
```

When the same reader also serves UI options, bind `Switon\Dict\OptionReaderInterface` to the same class.

If your code needs the exact current option surface, inspect it directly:

```bash
bash bin/console config:options 'Switon\Dict\DictReaderInterface'
```

---

## Keep in Mind {#keep-in-mind}

- Override `typeCode()` when the class name should not become the final dict type code.
- Make nullable parameters or properties explicit when empty input should stay `null`.
- Bind `OptionReaderInterface` only if UI code really needs dict-backed rows.

---

## Next {#next}

- **To handle common questions and edge cases:** [FAQ](faq.md#dict-faq)
- **To understand the design trade-offs:** [Design Rationale](design.md#design-rationale)
