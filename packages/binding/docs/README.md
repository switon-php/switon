# Binding Package {#binding-package}

`switon/binding` builds **method argument lists** from input, type-owned rules, and container state. Typed request objects are hydrated through the same binding layer.

**Key features:**

- **Type-owned resolution:** `#[ResolvedBy(...)]` pins one construction rule on the concrete type, not on each parameter.
- **Cross-package example:** `Switon\Orm\Page` is the clearest reference value type for `#[ResolvedBy(...)]`.
- **Action arguments:** `ArgumentsBinderInterface` resolves one `ReflectionMethod` to one positional argument list.
- **Typed input:** `InputBinderInterface` hydrates typed input objects from array payloads.
- **Self-building values:** `ArgumentResolvable` lets a value type participate in its own construction.

## Installation {#installation}

**Requirements:** PHP 8.3+

```bash
composer require switon/binding
```

## Quick Start {#quick-start}

### Bind Action Arguments {#bind-action-arguments}

Inject `ArgumentsBinderInterface`, resolve arguments, then pass them to `InvokerInterface`.
This example intentionally uses `Switon\Orm\Page` plus `switon/invoker` to show the seam around binding; they are companion packages, not dependencies of `switon/binding` itself.

```php
use ReflectionMethod;
use Switon\Binding\ArgumentsBinderInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Orm\Page;
use Switon\Invoking\InvokerInterface;

class ExampleController
{
    public function indexAction(Page $page): void
    {
    }
}

class Dispatcher
{
    #[Autowired] protected ArgumentsBinderInterface $argumentsBinder;
    #[Autowired] protected InvokerInterface $invoker;

    public function dispatch(object $controller, string $action): mixed
    {
        $method = new ReflectionMethod($controller, $action);
        $arguments = $this->argumentsBinder->resolve($method);

        return $this->invoker->invoke([$controller, $action], $arguments);
    }
}
```

- **Binding** chooses where each value comes from.
- **Invoker** performs the final call.
- **`Switon\Orm\Page`** is the clearest cross-package example of type-owned resolution.

## Core Concepts {#core-concepts}

### `#[ResolvedBy]` {#resolvedby}

Use `#[ResolvedBy(...)]` when one concrete type should always build the same way.

- Put the attribute on the **class**, not on each parameter.
- **`Switon\Orm\Page`** is the smallest real package example in the Switon docs.
- **`ArgumentResolvable`** is the same idea when the value type owns the rule.
- Copy the `Page` shape for your own types when one concrete value type should always resolve the same way. The sketch below uses a local `UserPage` example so the rule stays obvious without depending on ORM internals.

```php
use Switon\Binding\Attribute\ResolvedBy;
use Switon\Binding\ValueResolverInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\InputInterface;
use ReflectionParameter;

#[ResolvedBy(UserPageResolver::class)]
class UserPage
{
    public static function of(int $page, int $limit = 10): static
    {
        // real class body omitted
    }
}

final class UserPageResolver implements ValueResolverInterface
{
    #[Autowired] protected InputInterface $input;

    public function resolve(ReflectionParameter $parameter, string $type): mixed
    {
        return UserPage::of(
            (int)$this->input->get('page', 1),
            (int)$this->input->get('size', 10),
        );
    }
}
```

The resolver reads `page` and `size` from input, builds the value, and applies its own limit rules.

### Container Values {#container-values}

Interface-typed parameters and other unresolved objects fall through to the **container**.

### Scalar Input {#scalar-input}

Scalar parameters come from `Switon\Core\InputInterface`.

- Exact **parameter name** matches first.
- **`snake_case`** is the next lookup key.
- Values then follow the active input source’s normal reading rules.
- Binding continues through **defaults** when nothing is resolved.

### Typed Input {#typed-input}

Use `#[RequestBody]`, `#[RequestQuery]`, or `#[RequestData]` when one class should bind directly from request data.

```php
use Switon\Http\Attribute\RequestBody;

#[RequestBody]
class CreateUserInput
{
    public string $name;
    public ?string $email = null;
}

class UserController
{
    public function createAction(CreateUserInput $input): array
    {
        return ['name' => $input->name, 'email' => $input->email];
    }
}
```

- **`#[RequestBody]`** is the usual default for write actions.
- Use the other two only when the **source boundary** must be explicit.

### Option Discovery {#option-discovery}

Run:

```bash
bash bin/console config:options 'Switon\Binding\ScalarResolverInterface'
bash bin/console config:options 'Switon\Binding\ObjectResolverInterface'
```

## Keep in Mind {#keep-in-mind}

- Put `#[ResolvedBy]` on the **class**; do not attach it to a parameter.
- Interface-typed parameters still resolve through the **container** when no type-owned rule applies.
- Scalar parameters use `InputInterface` with **exact name** and **`snake_case`** fallback.
- Binding stops at the **argument list**; `InvokerInterface` performs the call.

## Next {#next}

- **After binding, run the callable:** [Invoker Package](../../invoker/docs/README.md)
- **HTTP payloads into typed input:** [HTTP typed input binding](../../http/docs/typed-input-binding.md#typed-input-binding)
- **FAQ:** [FAQ](faq.md#binding-faq)
- **Design:** [Design Rationale](design.md#design-rationale)
