# Renderer Package {#renderer-package}

`switon/renderer` runs templates through one shared `Frames` transaction. Choose it as the lower template layer with PHP and Sword engines, theme lookup, and fragment composition. It does not own controller routing or request lifecycle conventions; that is `switon/view`.

**Key features:**

- **`Frames` transactions:** one render call returns shared state, content, sections, stacks, and theme.
- **Two engines in one app:** native `.phtml` and compiled `.sword`, picked by file extension.
- **Template composition:** `partial`, `section`, `stack`, and `once` go through the runtime object.
- **Theme-aware lookup:** themed `View` paths override default templates when set on `Frames`.
- **Lifecycle hooks:** render events are available for observability.

---

## Installation {#installation}

**Requirements:** PHP 8.3+, `switon/core`, `switon/event`, `switon/sync`, `psr/event-dispatcher`

```bash
composer require switon/renderer
```

---

## Quick Start {#quick-start}

Inject `RendererInterface`, render one template, and read the content from `Frames`.

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Rendering\RendererInterface;

final class WelcomePage
{
    #[Autowired] protected RendererInterface $renderer;

    public function render(array $user): string
    {
        $frames = $this->renderer->render('@app/View/home', [
            'title' => 'Welcome',
            'user' => $user,
        ]);

        return $frames->content();
    }
}
```

Use `Frames` when multiple template executions must share state.

```php
use Switon\Rendering\Frames;

$frames = Frames::of(['title' => 'Welcome'])->setTheme('dark');
$this->renderer->render('@app/View/page', [], $frames);
$this->renderer->render('@app/View/layout', [], $frames);
```

---

## Core Concepts {#core-concepts}

### Render Transaction {#render-transaction}

`RendererInterface::render()` returns a `Switon\Rendering\Frames` object, not a raw string. `RendererInterface::exists()` checks whether a template name can be resolved after theme and engine lookup.

`Frames` carries the input vars, the final content (`content()` and `__toString()`), named sections, ordered stacks, per-transaction once markers, and the active theme. Rendering returns this transaction object instead of only a string so layout composition can read all of those at once.

### Template Runtime {#template-runtime}

Templates receive the active transaction as `$__frames`. Use it for `partial()`, `section()`, `stack()`, `renderStack()`, and `once()`. These methods only work while a render transaction is active; `partial()` outside a render call raises an exception.

### Engines {#engines}

`Renderer` picks the engine from the resolved file extension: `.phtml` runs through native PHP, `.sword` compiles to PHP first and then runs. Sword is a Blade-like directive format for teams that want template helpers without inline PHP. The engine table is wired through DI, so apps can add or replace extensions through container configuration.

### Template Paths {#template-paths}

Template names can be absolute aliases (`@app/View/home`) or relative to the current template (`sidebar`). When `Frames` carries a theme, paths under `/View/` are looked up in the themed location first, then fall back to the default.

### Precompile {#precompile}

`bash bin/console sword:compile` clears `@runtime/sword`, then compiles discovered `.sword` templates under `@app/View` and `@app/Areas/*/View`. `--replace` writes the compiled `.phtml` next to the source file instead of into the runtime cache.

---

## Keep in Mind {#keep-in-mind}

- `__frames` is reserved; user vars are extracted with `EXTR_SKIP`, so a colliding key is dropped instead of overwriting the runtime variable.
- Reuse one `Frames` object when renders must share sections, stacks, or theme; use a fresh one when renders should stay isolated.
- `e()` is the global HTML-escape helper.
- `switon/view` owns controller-oriented page flow; this package stays at the template layer.

---

## Next {#next}

- **Common questions:** [FAQ](faq.md#renderer-faq)
- **Trade-offs and boundaries:** [Design Rationale](design.md#design-rationale)
- **Controller page rendering:** [View Package](../view/README.md)
