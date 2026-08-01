# Build HTML pages {#build-html-pages}

Use this guide when you want one clear, task-first path for a small server-rendered HTML page in Switon before you add authentication, persistence-heavy workflows, or richer frontend build tooling.
For a first pass, prove one simple `ViewMapping` page before you add layouts, mixed shell/data routes, or page-specific auth.

## Components in play {#components-in-play}

- **[Kernel](../../packages/kernel/docs/README.md)** — bootstraps env, config, and services before request handling starts
- **[HTTP](../../packages/http/docs/README.md)** — provides the request/response lifecycle for the application
- **[Controller](../../packages/http/docs/controller.md#controller)** — shows how `RequestMapping`, `ViewMapping`, `ViewGetMapping`, and `ViewPostMapping` work together for controller-facing HTML routes (`RequestMapping` for shared route prefixes, `ViewMapping` for action-run HTML pages, `ViewGetMapping` / `ViewPostMapping` for mode-aware page actions)
- **[Router](../../packages/router/docs/README.md)** — registers the route shape and view-mapping behavior
- **[View](../../packages/view/docs/README.md)** — owns page conventions such as template mapping, layout state, widgets, and HTML response integration
- **[Renderer](../../packages/renderer/docs/README.md)** — owns low-level template execution, `.phtml` / `.sword`, sections, stacks, and partials
- **[DI](../../packages/di/docs/README.md)** — keeps controllers thin and moves page data loading into services

Common shape: controller mappings pick the route, `View` owns page conventions, `Renderer` executes templates, and services provide page data.

## Recommended build order {#build-order}

1. Start from a normal app bootstrap.
2. Add one route and choose one mapping style.
3. Let template resolution stay conventional first.
4. Add one layout only after the page path works.
5. Keep `View` and `Renderer` as separate concerns.
6. Move page data loading into a service.
7. Stop before auth, heavy persistence, caching, i18n, or frontend build tooling.

## Minimal wiring {#minimal-wiring}

Application-level names like `App\Controller\UserController` and `UserPageServiceInterface` below are illustrative. The Switon framework symbols are the stable part.
If your app applies a global router prefix such as `/api`, keep the controller mappings below prefix-free and confirm the real public URL with `bash bin/console router:prefix`.
If the first page returns `404`, check the public route first and the template path second. Route discovery problems are more common than broken first-page templates.

### Controller shape for a pure server-rendered page {#wiring-controller}

Start with `ViewMapping` when the HTML request should run the action and render one page from the returned vars:

```php
namespace App\Controller;

use App\Service\UserPageServiceInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Http\Controller;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Routing\Attribute\ViewMapping;

#[RequestMapping('/users')]
class UserController extends Controller
{
    #[Autowired] protected UserPageServiceInterface $pages;

    #[ViewMapping('{id}')]
    public function profileAction(int $id): array
    {
        return ['user' => $this->pages->profile($id)];
    }
}
```

With the default convention, `UserController::profileAction` resolves to the template path `app/View/User/Profile`, and the renderer then picks the matching template file such as `.sword` or `.phtml`.
For a first page, stay on this convention. It removes one whole class of path-mapping mistakes while you prove the HTML route itself.

### Template shape stays small first {#wiring-template}

Use one small template and one engine choice. `.sword` is a good default when you want `{{ ... }}` to escape HTML by default and you want composition directives such as layouts and sections.

Template example (`app/View/User/Profile.sword`):

```sword
<h1>{{ $user['name'] }}</h1>
<p>User #{{ $user['id'] }}</p>
```

You can use `.phtml` instead when direct PHP is simpler. `.sword` works with the default renderer engine map; if your app removed that mapping, restore `RendererInterface.engines` or use `.phtml` for this first page. The first page should prove the render path, not force an engine debate.

### Add a default layout only after the page path works {#wiring-layout}

Once the page route works, set one default layout in `switon.yml`:

```yaml
Switon\Viewing\ViewInterface:
  layout: '@view/Layouts/Default'
```

`@view/Layouts/Default` means "look under the current `@view` root". With the default alias, that points at `app/View/Layouts/Default` and the renderer resolves the matching template extension such as `.sword` or `.phtml`.

If one action needs an explicit template path or a non-default layout, use `ViewInterface` directly and write the HTML into `Switon\Http\ResponseInterface`. Do **not** return the rendered string from a normal HTTP action unless you intentionally want the generic action-return normalization path.

```php
namespace App\Controller;

use Switon\Core\Attribute\Autowired;
use Switon\Http\Controller;
use Switon\Http\ResponseInterface;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Viewing\ViewInterface;

#[RequestMapping('')]
class InviteController extends Controller
{
    #[Autowired] protected ViewInterface $view;
    #[Autowired] protected ResponseInterface $response;

    #[GetMapping('/invite')]
    public function inviteAction(): void
    {
        $content = $this->view
            ->setLayout('@view/Layouts/Admin')
            ->setVar('title', 'Invite user')
            ->render('@view/User/Invite');

        $this->response->text($content, 'text/html');
    }
}
```

Use this explicit path when the page should **not** follow the default `Controller::Action` convention and the route should still behave like a normal HTML endpoint.

### Use shell-plus-data mappings only when one URL really needs both modes {#wiring-shell-data}

For dashboards, lists, or settings screens, one URL may need an HTML shell on the first visit and a data path later.
That is where `ViewGetMapping` or `ViewPostMapping` fits.

```php
namespace App\Controller;

use App\Service\DashboardServiceInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Routing\Attribute\ViewGetMapping;

#[RequestMapping('')]
class DashboardController
{
    #[Autowired] protected DashboardServiceInterface $dashboard;

    #[ViewGetMapping('/dashboard')]
    public function indexAction(): array
    {
        return ['stats' => $this->dashboard->summary()];
    }
}
```

With that mapping:

- a regular non-AJAX browser visit renders the HTML shell without invoking the action
- a JSON/AJAX-style request invokes the action and returns the data path

This pattern is powerful, but it should come **after** one simple `ViewMapping` page already works.

## What to avoid first {#pitfalls}

- **Mixed shell/data routes everywhere** — start with one simple `ViewMapping` page before you optimize with `ViewGetMapping` or `ViewPostMapping`
- **Business logic in templates** — templates should present data, not decide core application behavior
- **Renderer as a page-routing layer** — keep `Renderer` for template execution and `View` for page conventions
- **Identity and access** — add [authentication](add-authentication.md#add-authentication), [Session](../../packages/session/docs/README.md), and [Authz](../../packages/authz/docs/README.md) after the first page route is stable
- **Broader frontend concerns** — asset pipelines, theme systems, hydration, and SPA tooling should not shape the first server-rendered page
- **Performance policy too early** — page caching and deployment tuning matter later, not before the HTML path is correct

## Go deeper {#go-deeper}

- **To learn the same path as a framework model first:** [Core concepts](../concepts/README.md#switon-fundamentals) · [Understand the request lifecycle](../concepts/request-lifecycle.md#request-lifecycle)
- **To build a JSON-first path instead:** [Build an API](build-an-api.md#build-an-api)
- **To go deeper on controller mappings and request-mode behavior:** [Controller](../../packages/http/docs/controller.md#view-rendering) · [Router](../../packages/router/docs/README.md#view-mappings)
- **To go deeper on page conventions, layouts, widgets, and request-scoped vars:** [View](../../packages/view/docs/README.md)
- **To go deeper on `.sword`, `.phtml`, sections, stacks, and partials:** [Renderer](../../packages/renderer/docs/README.md)
- **To add translated page text, request-scoped locale selection, or persisted language preference next:** [Add internationalization](add-internationalization.md#add-internationalization) · [I18n](../../packages/i18n/docs/README.md)
- **To keep controllers thin and move page data into services:** [DI](../../packages/di/docs/README.md) · [Understand service boundaries](../concepts/service-boundaries.md#service-boundaries)
- **To add login, sessions, or protected page routes next:** [Add authentication](add-authentication.md#add-authentication) · [Session](../../packages/session/docs/README.md) · [Authz](../../packages/authz/docs/README.md)
- **To persist or query the data behind the page next:** [Persist data](persist-data.md#persist-data) · [ORM](../../packages/orm/docs/README.md) · [DB](../../packages/db/docs/README.md)
- **To turn the page into a stable production shape:** [Deploy an app to production](deploy-an-app-to-production.md#deploy-an-app-to-production)
