---
layout: home
title: Switon
titleTemplate: Switon
description: Switon 框架的简体中文文档。
---

<div class="switon-home">

<p class="switon-home-slogan">面向 AI 时代的 PHP 框架。</p>

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

Switon 让这些契约直接留在类表面，而不是藏进深层框架内部。

## 为什么是 Switon {#why-switon}

- **紧凑 CRUD** — `Router`、`HTTP`、`ORM` 让路由映射、类型化输入、过滤、分页与仓储调用留在同一条可读的应用路径上。
- **统一启动** — `Kernel` 让 HTTP、CLI、worker 与自定义运行时共享同一套启动叙事，而不是各入口各自漂移。
- **可读 DI** — `DI` 让服务、监听器、命令与控制器保持同一种依赖风格；同一契约有多个实例时，可以用命名槽位区分。
- **共享上下文** — `ContextAware` 状态留在请求或协程本地，单例服务不必再分叉。
- **方法面绑定** — `Invoker`、`HTTP`、`CLI` 让参数解析、选项帮助与请求绑定保持可见，而不是藏进解析层。
- **生命周期点** — `HTTP`、`Session`、`Redis`、`Mail`、`Renderer`、`Executor` 都提供稳定的生命周期点，用于追踪、指标与横切行为。
- **AI 工具** — `CLI`、`#[Tool]`、`list --json` 与 `Beacon` 让应用具备机器可读的命令与技能表面。

## 可见的 HTTP 生命周期 {#http-lifecycle}

<HttpLifecycleDiagram />

异常逃出时，`ExceptionDispatcher` 仍然会运行，`RequestFailed` 仍然会派发，`ResponseAdjust` 也会继续走响应路径。

所以请求 ID、CORS、认证加载、归一化、计时与访问日志都可以落在可见的生命周期点上，而不是消失在单一的 `$next()` 栈里。

## 从教程开始 {#tutorial}

如果你想要最顺的起步路径，直接去 [教程](tutorial/README.md#tutorial)。
它会通过搭建一个应用，把 controller、DI、HTTP 与持久化串成一条连续流程。

</div>
