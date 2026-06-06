---
name: switon-sword
description: Use for Sword template authoring, cleanup, and review in Switon apps, especially when editing .sword files, layouts, widgets, blocks, or JS/JSON output embedded in templates.
---

# Sword templates

Use this skill for `.sword` template work in Switon apps.

## Core model

- Sword is a template layer for rendering and page composition, not a business layer.
- Keep templates focused on display, layout, light conditionals, and wiring existing data into HTML or inline JS.
- Move repeated business decisions, data shaping, permission aggregation, and heavy service access upstream.

## Data sources

- Prefer view vars first.
- Use `@inject('name', FQCN::class)` only for light template wiring.
- If a template starts pulling several services or reshaping data heavily, stop and move that work upstream.

## Output semantics

- Use `{{ ... }}` for normal text/HTML-escaped output.
- Use `{!! ... !!}` only when raw output is intentional.
- Use `json(...)` for JS/JSON literal output in templates.
- Do not hand-wrap dynamic values with quotes for JS strings.

## Rules

- Do not inline `\Switon\Core\App::get(...)` in `.sword` templates when `@inject` can express the dependency.
- Keep template logic light; move repeated business/data shaping upstream.

## Common structures

- Layouts: own shared shell, shared assets, and page-level bootstrap data.
- Blocks/partials: render small reusable fragments with explicit input vars.
- Widgets: use when the repo already models the fragment as a widget; do not reimplement widget loading by hand.
- Inline JS bootstrap: prefer one explicit payload or a few explicit scalars, all encoded with `json(...)`.

## Good

```blade
@inject('identity', 'Switon\Principal\IdentityInterface')
window.ADMIN_USER_NAME = {!! json($identity->getName()) !!};
```

```blade
window.bootstrapPayload = {!! json($payload) !!};
```

```blade
<title>{{$siteName}}</title>
@yield('script')
```

## Avoid

```blade
window.ADMIN_USER_NAME = {!! \Switon\Core\Json::stringify(\Switon\Core\App::get('Switon\Principal\IdentityInterface')->getName()) !!};
```

```blade
window.ADMIN_USER_NAME = "{{$identity->getName()}}";
```

```blade
<?php
$repo = \Switon\Core\App::get(\App\Repository\UserRepository::class);
$rows = $repo->all([...]);
?>
```

## Review checklist

- Is this data better prepared as a view var?
- Is `@inject` replacing a raw `App::get(...)` call?
- Is JS/JSON output using `json(...)` instead of manual quoting?
- Is `{{ ... }}` vs `{!! ... !!}` chosen intentionally?
- Is the template staying readable without turning into a service locator?
