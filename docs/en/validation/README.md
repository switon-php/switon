# Validation Package {#validation-package}

`switon/validation` validates fields with attributes, normalizes values in the same pass, and renders locale-aware error
messages.

**Key features:**

- **Two main entrypoints** — `validateValue()` for one field, `validateValues()` for one payload
- **Attribute-first rules** — built-in constraints live under `Switon\Validating\Attribute`
- **Normalization during validation** — rules such as `Trim`, `Defaults`, `Type`, `Email`, `Date`, `Lowercase`, and
  `Uppercase` can change the value
- **Localized messages** — locale files provide templates and labels
- **Fail-fast per field** — the first failed rule stops the rest for that field
- **HTTP-friendly errors** — `ValidateFailedException` carries the field error map and returns status `400`

---

## Installation {#installation}

**Requirements:** PHP 8.3+

```bash
composer require switon/validation
```

---

## Quick Start {#quick-start}

Inject `ValidatorInterface` and validate one payload.

```php
use Switon\Core\Attribute\Autowired;
use Switon\Validating\Attribute\Email;
use Switon\Validating\Attribute\Length;
use Switon\Validating\Attribute\Required;
use Switon\Validating\ValidatorInterface;

final class UserService
{
    #[Autowired] protected ValidatorInterface $validator;

    public function register(array $input): array
    {
        return $this->validator->validateValues($input, [
            'username' => [new Required(), new Length(4, 16)],
            'email' => [new Required(), new Email()],
        ]);
    }
}
```

If a field fails, `ValidateFailedException` is thrown. If a rule changes a value, the returned payload contains the
normalized value.

---

## Core Concepts {#core-concepts}

### Entrypoints {#entrypoints}

- `validateValue()` validates one field and returns the result
- `validateValues()` validates one payload and returns the validated array
- `beginValidate()` / `endValidate()` are for manual sessions inside framework code

For most application code, start with `validateValues()`.

### Rules {#rules}

Rules run in declaration order for each field.

- the first failed rule stops that field
- other fields still continue
- `Defaults` can fill missing or empty input
- `Type` can convert supported scalar and enum values

### Validation Session {#validation-session}

`Validation` carries the current `field`, `value`, `source`, and collected errors.

Use it when a custom constraint needs to share state or add an error directly.

### Built-In Constraints {#built-in-constraints}

Common groups:

- presence: `Required`, `NotEmpty`, `Defaults`
- type and identity: `Type`, `Date`, `EnumValue`, `ConstantValue`
- nested arrays: `ArrayOf`
- string shape: `Length`, `MinLength`, `MaxLength`, `Alpha`, `Alnum`, `Digit`, `Xdigit`
- format: `Email`, `Url`, `Ip`, `Uuid`, `Mobile`, `Account`, `Regex`
- numeric and sets: `Min`, `Max`, `Range`, `Decimal`, `In`, `NotIn`
- string edges: `StartsWith`, `EndsWith`
- cross-field: `EqualTo`
- normalization: `Trim`, `Lowercase`, `Uppercase`

### Message Labels {#message-labels}

Field labels can come from the constraint, template labels, standard i18n keys, or the raw field name.

That keeps error text readable without changing the field keys in the returned error map.

---

## Configuration {#configuration}

Override template directories and message behavior in `switon.yml` only when needed.

```yaml
# switon.yml
Switon\Validating\ValidatorInterface:
  dirs:
    - '@switon.validator.resources'
  fallback: en
  translateField: true
  translateLabel: false
```

### Option Discovery {#option-discovery}

```bash
bash bin/console config:options 'Switon\Validating\ValidatorInterface'
```

The default validator depends on `FilesystemInterface`, `LocaleInterface`, and `TranslatorInterface` being available in
the container.

---

## Keep in Mind {#keep-in-mind}

- `validateValues()` does not invent missing fields.
- `Defaults` can make a missing field appear in the returned array.
- Order matters when one rule normalizes a value for the next rule.
- Field order follows the constraint map, not the source array.
- Plain message strings are literal templates.
- `switon/http` owns request-source selection.

---

## Next {#next}

- **Need setup questions answered quickly:** [FAQ](faq.md#validation-faq)
- **Need design trade-offs:** [Design Rationale](design.md#design-rationale)
- **HTTP typed input binding:** [HTTP typed input binding](../http/typed-input-binding.md#typed-input-binding)
