---
description: 任务优先路线：在持久化、认证、缓存等更广议题之前，先打通一条小型 JSON API 路径。
---

# 构建 API {#build-an-api}

当你希望在 Switon 里先走出一条清晰、以任务为导向的小型 JSON API 路径，再考虑持久化、认证、缓存等更广议题时，使用本指南。

## 涉及的组件 {#components-in-play}

首条 API 路径需要这些组件协同：

- **[Kernel](../kernel/README.md)** — 在请求处理开始前引导环境、配置与服务
- **[HTTP](../http/README.md)** — 提供应用的请求/响应生命周期
- **[Router](../router/README.md)** — 将 URL 与方法映射到控制器 action
- **[Invoker](../invoker/README.md)** — 将请求数据绑定到 action 参数或[请求输入对象](../concepts/glossary.md#core-terms)
- **[Validation](../validation/README.md)** — 在业务逻辑运行前校验输入
- **[DI](../di/README.md)** — 保持控制器精简，业务逻辑放在可复用的 service 中

一条好的首条 API 让每个组件只做一件事：

- 路由决定 **去哪**
- 绑定决定 **拿什么**
- 校验决定 **能不能过**
- service 决定 **怎么做**

## 推荐构建顺序 {#build-order}

1. 从常规应用引导起步，经 [Kernel](../kernel/README.md) 拉起环境、配置与服务（主配置一般在根目录 `switon.yml`，应用类放在 `app/`）。
2. 加一条路由与一个控制器 action，先用 [HTTP](../http/README.md) 与 [Router](../router/README.md) 证明请求能到达 action 并得到清晰 JSON。
3. 用手动读取之外的方式绑定请求数据： [Invoker](../invoker/README.md) 与[类型化输入绑定](../http/typed-input-binding.md)。
4. 在输入边界用 [Validation](../validation/README.md) 校验，无效输入不应深入业务逻辑。
5. 把业务逻辑移入 service，由 [DI](../di/README.md) 注入，便于 HTTP、CLI 等入口复用。
6. 在持久化、认证、缓存、队列或文档等更广议题之前停下，先守住一条干净的请求、输入与 service 边界。

## 最小接线 {#minimal-wiring}

下面只用短代码展示组件之间的关键接缝。

下文中的 `App\Controller\UserController`、`CreateUserInput`、`App\Service\UserServiceInterface` 等应用级命名仅为示例；Switon 框架符号才是稳定部分。

写代码前先记住一条简单的放置规则：

- 应用 PHP 类放在 `app/` 下，例如 `app/Controller`、`app/Input`、`app/Service`
- 框架/服务配置放在根目录 `switon.yml`
- 仅在确实需要纯 PHP 或计算型配置值时使用 `config/*.php`

### 控制器形态 {#wiring-controller}

从一条显式路由与一个 JSON 响应开始：

```php
namespace App\Controller;

use Switon\Http\Controller;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('/user')]
class UserController extends Controller
{
    #[GetMapping('{id}')]
    public function showAction(int $id): array
    {
        return ['user' => ['id' => $id]];
    }
}
```

这足以验证首条请求路径：request → route → controller action → JSON response。
若应用已设置全局路由前缀，控制器映射应保持无前缀，不要在 attribute 路径里重复前缀。

### 输入边界 {#wiring-input}

首条路由可用后，在 action 边界增加请求绑定与校验。
把绑定结果当作输入对象，而不是另一种 request 对象。

```php
namespace App\Input;

use Switon\Http\Attribute\RequestBody;
use Switon\Validating\Attribute\Email;
use Switon\Validating\Attribute\Length;

#[RequestBody]
class CreateUserInput
{
    #[Length(4, 16)]
    public string $username;

    #[Email]
    public string $email;

    public ?int $age = null;
}
```

```php
namespace App\Controller;

use App\Input\CreateUserInput;
use Switon\Http\Controller;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('/user')]
class UserController extends Controller
{
    #[PostMapping('')]
    public function createAction(CreateUserInput $input): array
    {
        return ['username' => $input->username, 'email' => $input->email];
    }
}
```

首条输入形态保持精简：

- 一种 request body 或 query 形态
- 输入变复杂时用一种输入对象
- 业务逻辑之前有一层校验

更慢的逐步讲解见 [02 — 干净输入](../tutorial/02-input.md#02-input)；权威文档见[类型化输入绑定](../http/typed-input-binding.md)。

### Service 边界 {#wiring-service}

当 action 开始承载业务决策时，把工作移入 service，通过 [DI](../di/README.md) 注入。

```php
namespace App\Controller;

use App\Input\CreateUserInput;
use App\Service\UserServiceInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Http\Controller;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('/user')]
class UserController extends Controller
{
    #[Autowired] protected UserServiceInterface $userService;

    #[PostMapping('')]
    public function createAction(CreateUserInput $input): array
    {
        return ['user' => $this->userService->create($input)];
    }
}
```

要点是边界：控制器协调 HTTP 工作；service 拥有业务工作。
在 Switon 的同命名空间 DI 约定下，`UserServiceInterface` 与 `UserService` 这类配对在首通时通常不需要额外显式绑定块。
规则是字面的：接口与类在同一命名空间且共享同一基名时，Switon 自动将 `XxxInterface` 映射到 `Xxx`。
若类名不同、命名空间不同，或存在多个实现，应在 `switon.yml` 中写显式 DI 配置，不要指望约定去猜。
更慢的逐步讲解见 [03 — 服务边界](../tutorial/03-service.md#03-service)。

### 首条路由检查 {#wiring-route-check}

在叠加更多层次之前，先验证你真正在测的公开路径：

```bash
bash bin/console router:resolve /user/1 GET
```

本指南已过「应用是否活着」阶段，此处用 `router:resolve` 的主要目的是确认一条组装好的 API 路径是否对应你以为的公开 URL 与动词。

若命令结果与预期路由不符：

- 控制器映射看起来对、但公开 URL 可能仍带全局前缀（如 `/api`）时，运行 `bash bin/console router:prefix`
- 需要确认路由是否已注册时，运行 `bash bin/console router:list`

若应用加了 `/api` 等全局前缀，应解析完整公开路径，例如 `bash bin/console router:resolve /api/user/1 GET`。
路由前缀通常在根目录 `switon.yml` 或 `config/router.php` 中，取决于应用的引导方式。

## 首条路径上宜避免什么 {#pitfalls}

有意让首条 API 路径保持窄。

下列内容不宜过早加入：

- **认证** — [JWT](../jwt/README.md)、[Authz](../authz/README.md)、[Session](../session/README.md) 及相关身份议题属于后续路径
- **持久化设计** — 在单独的数据向路径中再选 [ORM](../orm/README.md) 或更低层的 [DB](../db/README.md)
- **缓存** — [Cache](../cache/README.md)、[Redis](../redis/README.md) 与 [HTTP Cache](../http-cache/README.md) 解决的是后续性能问题
- **异步工作** — [Queue](../queue/README.md) 与后台流程不应塑造首条 API 路径
- **页面渲染** — [构建 HTML 页面](build-html-pages.md#build-html-pages)、[View](../view/README.md) 与 [Renderer](../renderer/README.md) 对 JSON 优先的 API 并非必需
- **文档工具** — [添加 API 文档](add-api-docs.md#add-api-docs) 与 [OpenAPI](../openapi/README.md) 应在 API 形态稳定之后

## 继续深入 {#go-deeper}

- **按有序教程继续：** [教程](../tutorial/README.md#tutorial)
- **改走控制器到模板的首条页面路径：** [构建 HTML 页面](build-html-pages.md#build-html-pages)
- **深入引导与应用启动：** [Kernel](../kernel/README.md)
- **深入请求处理：** [HTTP](../http/README.md) · [Controller](../http/controller.md) · [Router](../router/README.md)
- **在认证与缓存之前放置请求级钩子、action 包装或 HTTP 失败策略：** [添加横切 HTTP 行为](add-cross-cutting-http-behavior.md#add-cross-cutting-http-behavior)
- **深入输入绑定：** [Invoker](../invoker/README.md) · [类型化输入绑定](../http/typed-input-binding.md)
- **深入校验规则：** [Validation](../validation/README.md)
- **把业务逻辑移入可复用 service：** [DI](../di/README.md)
- **在更广功能落地前保护首条稳定的 service 或请求路径：** [为已有应用添加测试](add-testing-to-a-working-app.md#add-testing-to-a-working-app)
- **HTTP 与数据边界就绪后处理上传、blob 键与应用内下载：** [处理文件上传与下载](handle-file-uploads-and-downloads.md#handle-file-uploads-and-downloads)
- **下一步加登录态、Bearer 令牌或受保护端点：** [添加认证](add-authentication.md#add-authentication) · [Session](../session/README.md) · [JWT](../jwt/README.md) · [Authz](../authz/README.md)
- **首条路由真实后保护登录或公开端点：** [用速率限制保护端点](protect-endpoints-with-rate-limits.md#protect-endpoints-with-rate-limits)
- **路由与认证形态稳定后文档化 API 契约：** [添加 API 文档](add-api-docs.md#add-api-docs) · [OpenAPI](../openapi/README.md)
- **把首条真实路由固化为稳定的运行时、配置与 worker 进程形态：** [将应用部署到生产环境](deploy-an-app-to-production.md#deploy-an-app-to-production)
- **下一步从控制台复用同一 service：** [添加 CLI](add-cli.md#add-cli) · [05 — CLI 复用](../tutorial/05-cli.md#05-cli) · [CLI](../cli/README.md)
- **下一步接入持久化数据：** [持久化数据](persist-data.md#persist-data) · [04 — 持久化数据](../tutorial/04-data.md#04-data) · [ORM](../orm/README.md) · [DB](../db/README.md)
