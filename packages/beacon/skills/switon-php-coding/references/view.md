# View (add / change / verify)

Use when the user asks to add or change a **view, template, layout, or widget**, or how to pass data from controller to
template.

## 1. Where views live

- **Root:** `app/View/` (e.g. `app/View/User/Profile.sword`). Map by path or by `Controller::action` (framework derives
  path from controller file and action name).
- **Areas:** `app/Areas/{Area}/View/` (e.g. `app/Areas/Admin/View/Session/Login.sword`). One view file per
  controller/action when using controller-based resolution.
- **Layouts:** Often `app/View/Layouts/Default.sword` or `app/Areas/{Area}/View/Layouts/Default.sword`. Set via
  `Switon\Viewing\ViewInterface::setLayout('@view/Layouts/Default')` or in `switon.yml` under
  `Switon\Viewing\ViewInterface` (`layout` key).

## 2. Controller → view

- **View actions:** Use `#[ViewMapping]`, `#[ViewGetMapping]`, `#[ViewPostMapping]`, `#[ViewPutMapping]`, or
  `#[ViewPatchMapping]` so the response can be rendered as HTML (exact GET vs POST vs PUT/PATCH behavior differs per
  attribute). See controller topic in this skill and the HTTP controller docs.
- **Data:** Action return value:
    - `array` → merged into view vars
    - object with `toArray()` → result merged
    - other object → stored as `data`
    - Or inject `Switon\Viewing\ViewInterface`, call `setVars([...])` then `render($template, $vars)`.
- **Template path:** Direct path (e.g. `@view/User/Profile`) or controller mapping (e.g.
  `App\Controller\UserController::profileAction`). `@view` alias usually points to `@app/View`.

## 3. Sword template basics

- **Output:** `{{ $var }}`, `{{ expression }}` — escaped by default.
- **Layout slot:** In layout, `@content()` outputs the rendered content of the child template.
- **Sections:** `@yield('name')` in layout; in child use `@section('name')` … `@endsection` to fill slots.
- **Control:** `@if`, `@else`, `@endif`; `@foreach` … `@endforeach`; `@unless` … `@endunless`; `@isset` … `@endisset`;
  `@empty` … `@endempty`; `@switch` … `@endswitch`.
- **Partials:** `@partial('path')` or similar to include another template.
- **Details:** For directive and rendering behavior, read the PHPDoc and implementation of the view interfaces and
  renderer classes used in your app (for example `Switon\Viewing\ViewInterface`, `Switon\Viewing\ViewRenderer`, and
  `Switon\Rendering\Engine\Sword`).

### 3.1 Core interfaces and classes

- **`Switon\Viewing\ViewInterface`** — main view contract used by controllers and listeners (`setLayout()`, `setVars()`,
  `render()`).
- **`Switon\Viewing\ViewRenderer`** — listens for `\Switon\Http\Event\RequestRendering`, maps action return values to
  view vars (with `VarsResolving` / `VarsResolved` hooks), calls `ViewInterface::render()` with the request `handler()`
  string, and writes HTML to the response.
- **`Switon\Viewing\WidgetInterface`** — contract for widgets used via `@widget(...)` in templates.
- **`Switon\Rendering\Engine\Sword`** — compiled engine for `.sword` templates (Blade-like directives).

### 3.2 Minimal layout + view example

- **Layout** (`app/View/Layouts/Default.sword`):

```sword
<!doctype html>
<html>
  <head>
    <title>@yield('title')</title>
  </head>
  <body>
    @widget('SideMenu')
    <main>
      @yield('content')
    </main>
  </body>
</html>
```

- **View** (`app/View/User/Profile.sword`):

```sword
@section('title')User profile@endsection

@section('content')
  <h1>{{ $user->name }}</h1>
  @if ($user->active)
    <span>Active</span>
  @else
    <span>Inactive</span>
  @endif
@endsection
```

## 4. Widgets

- **Purpose:** Reusable UI fragments (e.g. sidebar menu, header). Implement `Switon\Viewing\WidgetInterface` or extend
  app base (e.g. `App\Widget\Widget`). Method `run($vars = [])` returns `array` (vars for widget template) or `string` (
  HTML output directly).
- **Location:** `app/Widget/`. Class name = short name + `Widget` (e.g. `SideMenuWidget`). Use `#[Autowired]` in widget
  class for dependencies.
- **In template:** `@widget('SideMenu')` or `@widget('SideMenu', ['option' => 'value'])`. Short name `SideMenu` resolves
  to `App\Widget\SideMenuWidget` (configurable via `widgetNamespace` in `switon.yml` under
  `Switon\Viewing\ViewInterface`).
- **Widget template:** When `run()` returns an array, the view engine renders the widget template (convention: e.g.
  `app/View/Widget/SideMenu.sword`) with that array as vars.
- **Authorization-aware widgets:** For permission-based menus or UI elements, inject
  `Switon\Authorizing\AuthorizationInterface` and use `can($permissionCode)` to filter items;
  see [references/authorize.md](references/authorize.md) and [references/rbac.md](references/rbac.md) for permission and
  role conventions.

## 5. Config

- **switon.yml:** Use the `Switon\Viewing\ViewInterface` key. Common options: `layout`, `rewrite`, `widgetNamespace` (
  e.g. `App\Widget`). See implementation `#[Autowired]` properties in `switon/view`.

## 6. I18n and text

- **Helper:** `t(string $id, array $bind = [], bool $useICU = false): string` →
  `Switon\Core\TranslatorInterface::translate()` (`useICU=true` uses ICU MessageFormat; `false` does simple placeholder
  replacement)

## 7. Source of truth

- View interfaces and renderer implementation used in the app (e.g. `Switon\Viewing\ViewInterface` and renderer
  classes). In app: resolve them via `vendor/switon/` and follow their PHPDoc and source.
