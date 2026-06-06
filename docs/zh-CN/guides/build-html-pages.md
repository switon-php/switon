---
description: 任务优先路线：在认证、重持久化流程或更重前端构建工具之前，先打通一条小型服务端渲染 HTML 页面路径。
---

# 构建 HTML 页面 {#build-html-pages}

当你希望在 Switon 里先走出一条清晰、以任务为导向的小型服务端渲染 HTML 页面路径，再考虑认证、重持久化流程或更丰富的前端构建工具时，使用本指南。

## 涉及的组件 {#components-in-play}

首条 HTML 页面路径需要这些组件协同：

- **[Kernel](../kernel/README.md)** — 在请求处理开始前引导环境、配置与服务
- **[HTTP](../http/README.md)** — 提供应用的请求/响应生命周期
- **[Controller](../http/controller.md)** — 说明面向控制器的 HTML 路由中 `RequestMapping`、`ViewMapping`、`ViewGetMapping` 与 `ViewPostMapping` 如何配合（`RequestMapping` 共享路由前缀，`ViewMapping` 用于执行 action 的 HTML 页面，`ViewGetMapping` / `ViewPostMapping` 用于按模式区分的页面 action）
- **[Router](../router/README.md)** — 注册路由形态与 view 映射行为
- **[View](../view/README.md)** — 拥有页面约定：模板映射、布局状态、组件（widget）与 HTML 响应集成
- **[Renderer](../renderer/README.md)** — 拥有底层模板执行：`.phtml` / `.sword`、section、stack 与 partial
- **[DI](../di/README.md)** — 保持控制器精简，页面数据加载移入 service

一条好的首条 HTML 路径让每个组件只做一件事：

- 控制器映射决定 **哪条路径、哪种 HTTP 动词执行 action**
- view 决定 **适用哪种页面约定**
- renderer 决定 **模板如何执行**
- service 决定 **页面需要什么数据**

## 推荐构建顺序 {#build-order}

1. 从常规应用引导起步（[Kernel](../kernel/README.md)），不要用临时 include 或手写响应体凑页面。
2. 加一条路由并选定一种映射风格（首条成功页多为 `ViewMapping`；需要「同 URL 先 HTML 外壳、后 JSON」时用 `ViewGetMapping`；需要同 URL 的 `GET` 表单 + `POST` 提交时用 `ViewPostMapping`）。
3. 先让模板解析走约定，必要时再写显式 `@view/...`。
4. 页面能渲染后再加布局与少量共享页面状态。
5. 分清 [View](../view/README.md)（页面约定）与 [Renderer](../renderer/README.md)（模板执行），不要把 Renderer 当路由层。
6. 把页面数据加载移入 service，经 [DI](../di/README.md) 注入。
7. 在认证、重持久化、缓存、i18n 或前端构建链等更广议题之前停下。

## 最小接线 {#minimal-wiring}

下面只用短代码展示组件之间的关键接缝。

下文中的 `App\Controller\UserController`、`UserPageServiceInterface` 等应用级命名仅为示例；Switon 框架符号才是稳定部分。
若应用使用 `/api` 等全局路由前缀，控制器映射应保持无前缀，并用 `bash bin/console router:prefix` 确认真实公开 URL。

### 纯服务端渲染页面的控制器形态 {#wiring-controller}

当 HTML 请求应执行 action 并从返回的变量渲染一页时，从 `ViewMapping` 开始：

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

这给出首条有用的 HTML 路径：

- 路由匹配 `/users/{id}`
- action 运行并返回页面数据
- `ViewRenderer` 将数据映射为模板变量
- Switon 根据 `UserController::profileAction` 解析处理器模板

按默认约定，`UserController::profileAction` 解析为模板路径 `app/View/User/Profile`，renderer 再选择匹配的模板文件（如 `.sword` 或 `.phtml`）。
该约定基于文件：

- 控制器名 `UserController` 对应目录 `app/View/User/`
- action 名 `profileAction` 对应模板名 `Profile`
- 需要自行渲染非常规路径时，仍可使用显式 `@view/...` 别名

### 模板形态先保持精简 {#wiring-template}

用一个小的模板、一种引擎。若希望 `{{ ... }}` 默认转义 HTML，并需要布局、section 等组合指令，`.sword` 是不错的默认选择。

模板示例（`app/View/User/Profile.sword`）：

```sword
<h1>{{ $user['name'] }}</h1>
<p>User #{{ $user['id'] }}</p>
```

需要直接写 PHP 时可用 `.phtml`。`.sword` 配合默认 renderer 引擎映射；若应用移除了该映射，恢复 `RendererInterface.engines` 或首条页面先用 `.phtml`。首条页面应证明渲染路径可用，而不是争论引擎选型。

### 页面路径可用后再加默认布局 {#wiring-layout}

页面路由可用后，在 `switon.yml` 中设置一个默认布局：

```yaml
Switon\Viewing\ViewInterface:
  layout: '@view/Layouts/Default'
```

此处的 `ViewInterface` 是 `switon/view` 包面向应用的 API。控制器需要选择布局、设置变量或显式渲染页面时注入它。`ViewRenderer` 是 HTTP 桥接层，把控制器返回值变成 HTML 响应。`Renderer` 仍是执行 `.sword` 或 `.phtml` 的更低层。

`@view/Layouts/Default` 表示「在当前 `@view` 根下查找」。默认别名下指向 `app/View/Layouts/Default`，renderer 再解析匹配的模板扩展名（如 `.sword` 或 `.phtml`）。

若某个 action 需要显式模板路径或非默认布局，直接使用 `ViewInterface`，并把 HTML 写入 `Switon\Http\ResponseInterface`。**不要**从普通 HTTP action 返回已渲染的字符串，除非你刻意要走通用的 action 返回值规范化路径。

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

当页面**不应**遵循默认的 `Controller::Action` 约定、但路由仍应像普通 HTML 端点一样行为时，使用这条显式路径。

### 仅当同一 URL 确实需要两种模式时才用外壳+数据映射 {#wiring-shell-data}

仪表盘、列表或设置页等场景，同一 URL 可能在首次访问时需要 HTML 外壳、后续再要数据路径。
此时适合 `ViewGetMapping` 或 `ViewPostMapping`。

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

使用该映射时：

- 普通非 AJAX 浏览器访问会渲染 HTML 外壳，且不调用 action
- JSON/AJAX 式请求会调用 action 并走数据路径

这一模式很强，但应在**一条简单的 `ViewMapping` 页面已经可用之后**再引入。

## 首条路径上宜避免什么 {#pitfalls}

有意让首条 HTML 页面保持窄。

下列内容不宜过早加入：

- **处处混用外壳/数据路由** — 先用一条简单的 `ViewMapping` 页面，再用 `ViewGetMapping` 或 `ViewPostMapping` 优化
- **模板里的业务逻辑** — 模板应呈现数据，不应决定核心应用行为
- **把 Renderer 当页面路由层** — `Renderer` 负责模板执行，`View` 负责页面约定
- **身份与访问** — 首条页面路由稳定后再加[认证](add-authentication.md#add-authentication)、[Session](../session/README.md) 与 [Authz](../authz/README.md)
- **更广的前端关切** — 资源流水线、主题系统、注水与 SPA 工具不应塑造首条服务端渲染页面
- **过早的性能策略** — 页面缓存与部署调优应在 HTML 路径正确之后再谈

## 继续深入 {#go-deeper}

- **先以框架模型学习同一路径：** [核心概念](../concepts/README.md) · [理解请求生命周期](../concepts/request-lifecycle.md)
- **改走 JSON 优先路径：** [构建 API](build-an-api.md#build-an-api)
- **深入控制器映射与请求模式行为：** [Controller](../http/controller.md#view-rendering) · [Router](../router/README.md#view-mappings)
- **深入页面约定、布局、组件与请求作用域变量：** [View](../view/README.md)
- **深入 `.sword`、`.phtml`、section、stack 与 partial：** [Renderer](../renderer/README.md)
- **下一步加译文、请求级区域设置或持久化语言偏好：** [添加国际化](add-internationalization.md#add-internationalization) · [I18n](../i18n/README.md)
- **保持控制器精简、把页面数据移入 service：** [DI](../di/README.md) · [理解服务边界](../concepts/service-boundaries.md)
- **下一步加登录、会话或受保护页面路由：** [添加认证](add-authentication.md#add-authentication) · [Session](../session/README.md) · [Authz](../authz/README.md)
- **下一步持久化或查询页面背后的数据：** [持久化数据](persist-data.md#persist-data) · [ORM](../orm/README.md) · [DB](../db/README.md)
- **把页面固化为稳定的生产形态：** [将应用部署到生产环境](deploy-an-app-to-production.md#deploy-an-app-to-production)
