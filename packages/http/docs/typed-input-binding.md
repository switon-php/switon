# Typed Input Binding {#typed-input-binding}

Bind request data into typed input classes with source-specific attributes and validation.

**Key features:**

- **Source selection** - `#[RequestBody]`, `#[RequestQuery]`, and `#[RequestData]` choose the request source
- **Root-class contract** - the attribute belongs on the input class, not on nested properties
- **Validation during binding** - public properties are populated and validated together
- **Nested objects and arrays** - typed input can contain nested objects and typed arrays

## Quick Start {#quick-start}

Mark one input class, then use it as an action parameter.

```php
namespace App\Input;

use Switon\Http\Attribute\RequestBody;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Validating\Attribute\Email;
use Switon\Validating\Attribute\Length;

#[RequestBody]
class CreateUserInput
{
    #[Length(4, 16)]
    public string $username;

    #[Email]
    public string $email;
}

#[RequestMapping('/user')]
class UserController
{
    #[PostMapping('')]
    public function createAction(CreateUserInput $input): array
    {
        return ['username' => $input->username, 'email' => $input->email];
    }
}
```

Use `#[RequestBody]` for body input, `#[RequestQuery]` for query input, and `#[RequestData]` when the input object should read merged request data.

## Core Concepts {#core-concepts}

### Source Attributes {#source-attributes}

The three typed-input attributes select the source array used by the binder.

- `#[RequestBody]` binds from parsed body data
- `#[RequestQuery]` binds from query data
- `#[RequestData]` binds from merged request data

### Binding Model {#binding-model}

Typed input binding works on the root input class.

- public properties receive request values
- missing required properties fail validation
- nested object properties are populated recursively

### Validation {#validation}

Validation attributes run during binding.

- use `Switon\Validating\Attribute\Length`
- use `Switon\Validating\Attribute\Email`
- use `Switon\Validating\Attribute\Required` only when the default rule is not enough

### Arrays And Nested Objects {#arrays-nested-objects}

Typed input can include nested objects and arrays of objects.

- nested object fields use dot-path error keys such as `user.name`
- arrays can validate each item through the normal binder path

## Keep in Mind {#keep-in-mind}

- Only the root input class needs the typed-input attribute.
- `RequestBody` and `RequestQuery` stay source-specific.
- Use `RequestData` only when merged input is the intended contract.

## Next {#next}

- **To see controller usage:** [Controller](controller.md#controller)
- **To see raw request reads:** [Request](request.md#request)
- **To learn validation details:** [FAQ](faq.md#http-faq)
