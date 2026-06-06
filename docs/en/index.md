---
layout: home
title: Switon
titleTemplate: Switon
description: Modular PHP framework for clear, scalable applications and backend systems.
---

<div class="switon-home">

<p class="switon-home-slogan">A PHP framework for the AI era.</p>

<div class="switon-home-code-shell">

::: code-group

```php [context]
<?php

use Switon\Core\Attribute\Autowired;
use Switon\Core\ContextAware;
use Switon\Core\ContextManagerInterface;

class IdentityContext
{
    public ?string $name = null;
}

class Identity implements ContextAware
{
    #[Autowired] protected ContextManagerInterface $contextManager;

    public function getContext(): IdentityContext
    {
        return $this->contextManager->getContext($this);
    }

    public function login(string $name): void
    {
        $context = $this->getContext();
        $context->name = $name;
    }

    public function logout(): void
    {
        $context = $this->getContext();
        $context->name = null;
    }
}
```

```php [crud]
<?php

namespace App\Controller;

use Switon\Core\Attribute\Autowired;
use App\Entity\Product;
use App\Repository\ProductRepository;
use Switon\Http\Attribute\Fields;
use Switon\Http\Attribute\Filters;
use Switon\Http\Attribute\Keyword;
use Switon\Http\Attribute\Orders;
use Switon\Http\Criteria;
use Switon\Http\RequestInterface;
use Switon\Orm\Page;
use Switon\Query\Paginator;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Routing\Attribute\ViewGetMapping;

#[RequestMapping('/catalog/product')]
class ProductController extends Controller
{
    #[Autowired] protected ProductRepository $productRepository;

    #[ViewGetMapping]
    #[Filters(['status', 'created_at@='])]
    #[Fields(['product_id', 'product_name', 'price', 'status', 'updated_at'])]
    #[Orders(['product_id' => SORT_DESC])]
    #[Keyword(['product_name'])]
    public function indexAction(Page $page, Criteria $criteria): Paginator
    {
        return $this->productRepository->paginate($page, $criteria->filters, $criteria->fields, $criteria->orders);
    }

    #[GetMapping]
    #[Fields(['product_id', 'product_name'])]
    #[Orders(['product_name' => SORT_ASC, 'product_id' => SORT_ASC])]
    public function listAction(Criteria $criteria): array
    {
        return $this->productRepository->all($criteria->filters, $criteria->fields, $criteria->orders);
    }

    #[GetMapping]
    public function detailAction(Product $product): Product
    {
        return $product;
    }

    #[PostMapping]
    public function createAction(RequestInterface $request): Product
    {
        return $this->productRepository->create($request->all());
    }

    #[PostMapping]
    public function editAction(Product $product): Product
    {
        return $this->productRepository->update($product);
    }

    #[PostMapping]
    public function deleteAction(Product $product): Product
    {
        return $this->productRepository->delete($product);
    }
}
```

```php [cli]
<?php

namespace App\Command;

use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;

class ReportCommand
{
    #[Autowired] protected ConsoleInterface $console;

    public function dailyAction(string $date = '2026-05-03'): void
    {
        $this->console->success('Report ready for {date}.', ['date' => $date]);
    }
}
```

```php [autowired]
<?php

use Switon\Core\Attribute\Autowired;
use Switon\Core\RequestInterface;

class BearerToken implements BearerTokenInterface
{
    #[Autowired] protected RequestInterface $request;

    public function extract(): ?string
    {
        return $this->request->header('authorization');
    }
}
```

:::

</div>

Switon keeps these contracts visible on the class surface instead of hiding them in deep framework internals.

## Why Switon {#why-switon}

- **Compact CRUD** — `Router`, `HTTP`, and `ORM` keep route mapping, typed input, filters, pagination, and repository calls on one readable application path.
- **Bootstrap contract** — `Kernel` gives HTTP, CLI, workers, and custom runtimes the same startup story instead of letting each entrypoint drift.
- **Readable DI** — `DI` keeps services, listeners, commands, and controllers on one recognizable dependency style, with named slots when one contract has multiple instances.
- **Shared context** — `ContextAware` state stays request- or coroutine-local, so singleton services do not have to fork into runtime-specific versions.
- **Method-surface binding** — `Invoker`, `HTTP`, and `CLI` keep argument resolution, option help, and request binding visible instead of hiding them in parser layers.
- **Lifecycle points** — `HTTP`, `Session`, `Redis`, `Mail`, `Renderer`, and `Executor` all expose stable lifecycle points for tracing, metrics, and cross-cutting behavior.
- **AI tooling** — `CLI`, `#[Tool]`, `list --json`, and `Beacon` give apps a machine-readable command and skill surface instead of treating AI support as an after-market addon.

## One visible HTTP lifecycle {#http-lifecycle}

<HttpLifecycleDiagram />

When an exception escapes, `ExceptionDispatcher` still runs, `RequestFailed` still fires, and `ResponseAdjust` continues the response path.

This is why request IDs, CORS, auth loading, normalization, timing, and access logs can stay on visible lifecycle points instead of vanishing into one opaque `$next()` stack.

## Start with the tutorial {#tutorial}

If you want the smoothest first path, go straight to [Tutorial](tutorial/README.md#tutorial).
It walks through the framework by building an app, so you can see the controller, DI, HTTP, and persistence shape in one continuous flow.

</div>
