---
description: 在已有 HTTP 路径上接入登录态、Bearer 令牌与端点保护：Session、JWT、Identity 与 Authz 的最小接线。
---

# 添加认证 {#add-authentication}

当一条可用的 HTTP 路径需要登录态、Bearer 令牌或端点保护，而你又不希望过早把身份平台设计变成应用中心时，使用本指南。

## 选择第一种认证形态 {#authentication-shape}

从与真实调用方边界匹配的传输形态起步。

| 形态 | 最适合 | 注意 |
|---|---|---|
| [Session](../session/README.md) | 浏览器优先的登录/登出/当前用户流应在服务端保状态，客户端只传 Session Cookie | 服务端渲染或同源应用流的默认首选；Cookie 作用域与延迟写入 Session 很重要 |
| [JWT](../jwt/README.md) | API 客户端、移动应用或服务调用方应在每次请求携带 Bearer 令牌 | `decode()` 即信任边界；保持声明紧凑且明确 |
| [Authz](../authz/README.md) | 调用方身份已存在，端点需要访问规则 | Authz **不会**自行完成认证；它在认证层填充 `IdentityInterface` 之后消费该接口 |

一种常见应用组合是：

- 浏览器登录/登出/当前用户流用 **Session**
- 显式 Bearer 令牌的 API 客户端用 **JWT**
- 在任一路径都能可靠填充 `IdentityInterface` 之后，再接入 **Authz**

此处还有一条路由细节：

- 若应用已配置全局路由前缀，控制器路径应保持无前缀
- 若公开 URL 形态不清晰，在调试登录或当前用户路由前先用 `bash bin/console router:prefix` 确认

## 推荐构建顺序 {#build-order}

1. 仅在至少一条 HTTP 路径已经可用之后再开始。
2. 按调用方传输形态选择 Session 或 JWT。
3. 在一个请求级监听器中加载 `IdentityInterface`（通过 `Switon\Http\RequestHandlerInterface` → `filters` 接线，在控制器运行前填充身份；`filters` 在此指请求级生命周期监听器，而非 `$next()` 链）。
4. 保持登录态小而明确。
5. 仅在身份已可用之后添加 `#[Authorize]`。
6. 仅在身份差异已经明确之后再重新审视缓存（含对 [HTTP Cache](../http-cache/README.md) 是否合适的首轮判断）。

若应用已配置全局路由前缀，控制器路径应保持无前缀；公开 URL 不清晰时，在调试登录或 `401`/`403` 之前先用 `bash bin/console router:prefix` 确认。

## 最小接线 {#minimal-wiring}

下文代码仅展示 HTTP、身份加载与授权之间的接缝。

下文中的 `UserRepository`、`LoginService`、`AuthController` 等应用层名称仅为示例；Switon 框架符号与配置面才是稳定部分。

### 共享的请求级认证钩子 {#wiring-request-hook}

在 HTTP 生命周期中注册一个认证监听器：

```yaml
# switon.yml (project root)
Switon\Http\RequestHandlerInterface:
  filters:
    auth: App\Listener\SessionIdentityListener
```

当调用方边界以令牌为先时，将该类换成基于 JWT 的监听器。
关键是接缝：请求级认证属于 HTTP 监听器接线，不应散落在各控制器。
此处的 `filters` 仍是生命周期监听器的配置键。
该块位于项目根目录的 `switon.yml`，即常规应用配置文件。
监听器类本身是常规应用代码，通常放在 `app/Listener/`。
对该 HTTP 路径，框架通过 `Switon\Http\RequestHandlerInterface` -> `filters` 获知监听器；`#[EventListener]` 标记该类上哪个方法应响应生命周期事件。

### Session 优先的浏览器流 {#wiring-session}

当登录态应留在服务端并通过 Cookie 传递时，使用 [Session](../session/README.md)：

```php
namespace App\Listener;

use App\Repository\UserRepository;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Attribute\EventListener;
use Switon\Principal\IdentityInterface;
use Switon\Http\Event\RequestBegin;
use Switon\Session\SessionInterface;

class SessionIdentityListener
{
    #[Autowired] protected SessionInterface $session;
    #[Autowired] protected IdentityInterface $identity;
    #[Autowired] protected UserRepository $userRepository;

    #[EventListener]
    public function onBegin(RequestBegin $event): void
    {
        $userId = $this->session->get('user_id');

        if ($userId === null) {
            $this->identity->clear();
            return;
        }

        $user = $this->userRepository->find((int) $userId);

        if ($user === null) {
            $this->session->destroy();
            $this->identity->clear();
            return;
        }

        $this->identity->set($user->id, $user->email, $user->roles);
    }
}
```

`RequestBegin` 是最早的常规请求生命周期事件之一。
在此使用它，是因为当前调用方应在控制器 action 与 `#[Authorize]` 检查之前加载完成。

`IdentityInterface::set()` 为 `set(id, name, roles)`：

- 第一个参数 = `getId()` 的稳定主体 id
- 第二个参数 = `getName()` 的显示名或主体名
- 第三个参数 = `getRoles()` 的规范化角色列表

在本 Session 示例中即：

- `$user->id` 对应 `IdentityInterface::getId()`
- `$user->email` 对应 `IdentityInterface::getName()`
- `$user->roles` 对应 `IdentityInterface::getRoles()`

```php
namespace App\Controller;

use App\Input\LoginInput;
use App\Service\LoginServiceInterface;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Core\Attribute\Autowired;
use Switon\Principal\IdentityInterface;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Session\SessionInterface;

#[RequestMapping('/auth')]
class AuthController
{
    #[Autowired] protected LoginServiceInterface $loginService;
    #[Autowired] protected SessionInterface $session;

    #[PostMapping('/login')]
    #[Authorize(Authorize::ANONYMOUS)]
    public function loginAction(LoginInput $input): array
    {
        $user = $this->loginService->verify($input->email, $input->password);
        $this->session->set('user_id', $user->id);

        return ['ok' => true];
    }

    #[GetMapping('/me')]
    #[Authorize(Authorize::AUTHENTICATED)]
    public function meAction(IdentityInterface $identity): array
    {
        return [
            'id' => $identity->getId(),
            'name' => $identity->getName(),
            'roles' => $identity->getRoles(),
        ];
    }

    #[PostMapping('/logout')]
    #[Authorize(Authorize::AUTHENTICATED)]
    public function logoutAction(): array
    {
        $this->session->destroy();

        return ['ok' => true];
    }
}
```

该路径把可变登录态留在服务端，由 Session 处理 Cookie 与持久化时机。
保持存储标记小巧；通过你自己的 repository/service 边界重新加载当前用户。
登录输入也应命名为输入对象，使原始 `RequestInterface` 与绑定的凭证输入在视觉上分离。

### JWT 优先的 API 流 {#wiring-jwt}

当调用方应在每次请求发送 Bearer 令牌时，使用 [JWT](../jwt/README.md)：

```yaml
# switon.yml (project root)
Switon\Jwt\JwtInterface:
  alg: HS256
  key: ${JWT_KEY}

Switon\Http\RequestHandlerInterface:
  filters:
    auth: App\Listener\JwtIdentityListener
```

该 JWT 配置同样位于根目录 `switon.yml`。

```php
namespace App\Listener;

use Switon\Core\Attribute\Autowired;
use Switon\Core\Attribute\EventListener;
use Switon\Principal\IdentityInterface;
use Switon\Http\Event\RequestBegin;
use Switon\Http\RequestInterface;
use Switon\Jwt\JwtInterface;

class JwtIdentityListener
{
    #[Autowired] protected RequestInterface $request;
    #[Autowired] protected JwtInterface $jwt;
    #[Autowired] protected IdentityInterface $identity;

    #[EventListener]
    public function onBegin(RequestBegin $event): void
    {
        $header = (string) $this->request->header('authorization', '');

        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            $this->identity->clear();
            return;
        }

        try {
            $claims = $this->jwt->decode($matches[1]);
            $this->identity->set(
                (string) ($claims['sub'] ?? ''),
                (string) ($claims['name'] ?? $claims['sub'] ?? ''),
                (array) ($claims['roles'] ?? []),
            );
        } catch (\Throwable) {
            $this->identity->clear();
        }
    }
}
```

此处保持相同的 identity 形态：

- `set(id, name, roles)` 参数顺序不变
- `sub` 对应 `getId()`
- `name` 对应 `getName()`
- `roles` 对应 `getRoles()`

```php
namespace App\Controller;

use App\Input\LoginInput;
use App\Service\LoginServiceInterface;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Core\Attribute\Autowired;
use Switon\Principal\IdentityInterface;
use Switon\Jwt\JwtInterface;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('/auth')]
class ApiAuthController
{
    #[Autowired] protected LoginServiceInterface $loginService;
    #[Autowired] protected JwtInterface $jwt;

    #[PostMapping('/login')]
    #[Authorize(Authorize::ANONYMOUS)]
    public function loginAction(LoginInput $input): array
    {
        $user = $this->loginService->verify($input->email, $input->password);

        return [
            'token' => $this->jwt->encode([
                'sub' => (string) $user->id,
                'name' => $user->email,
                'roles' => $user->roles,
            ], 3600),
        ];
    }

    #[GetMapping('/me')]
    #[Authorize(Authorize::AUTHENTICATED)]
    public function meAction(IdentityInterface $identity): array
    {
        return [
            'id' => $identity->getId(),
            'name' => $identity->getName(),
            'roles' => $identity->getRoles(),
        ];
    }
}
```

这使 `JwtInterface::decode()` 成为信任边界，并在请求头中显式体现调用方传输形态。
仅当不同失败结果确实需要区分时，才在应用代码中捕获具体 JWT 异常。
若应用配置了 `/api` 等全局路由前缀，公开路径会是 `/api/login` 与 `/api/me`，尽管控制器映射仍无前缀。

### 身份就绪后的授权 {#wiring-authz}

身份加载可用后，用 [Authz](../authz/README.md) 保护处理器：

```yaml
# switon.yml (project root)
Switon\Authorizing\LookupInterface:
  permissions:
    admin: admin::audit-log
```

该 permissions 块同样属于根目录 `switon.yml`。
心智模型保持简单：

- 监听器类在 `app/Listener/...`
- 控制器类在 `app/Controller/...`
- Authz 权限映射在 `switon.yml`

```php
namespace App\Controller;

use Switon\Authorizing\Attribute\Authorize;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('/admin')]
class AdminController
{
    #[GetMapping('audit-log')]
    #[Authorize('admin')]
    public function auditLogAction(): array
    {
        return ['ok' => true];
    }
}
```

首条规则保持精简：

- 登录/访客端点用 `ANONYMOUS`
- 当前用户或仅登录可用端点用 `AUTHENTICATED`
- 在身份来源与角色形态已清晰之后，再引入如 `admin` 的业务角色

## 初期应避免什么 {#pitfalls}

让第一条认证路径刻意保持窄。

过早避免：

- **把 `Authz` 当作登录系统** — 它在身份存在之后执行访问控制，不会自行创建身份
- **在 Session/JWT 中存完整实体或密钥** — 优先紧凑 id、名称与规范化角色列表
- **用 `HTTP Cache` 缓存隐藏的当前用户输出** — 仅随 Session、令牌或 Cookie 变化的差异默认不是安全的页面缓存键
- **在常规请求认证中使用直接的 `read()` / `write()` Session API** — 普通请求代码应使用 `get()` / `set()`
- **在同一属性中混用 `AUTHENTICATED` 或 `ANONYMOUS` 与自定义角色** — 身份状态标记应单独使用
- **把首条认证路径扩展为 MFA、联邦或完整 RBAC 工具** — 先证明一条清晰的登录/当前用户/受保护端点边界

完成第一条认证路径后，初学者应能感到：

> 我知道当前用户状态从何而来、端点如何看到它、访问规则应放在何处。

## 延伸阅读 {#go-deeper}

- **在首条请求路径之后衔接：** [构建 API](build-an-api.md#build-an-api) · [HTTP](../http/README.md)
- **在用户或角色数据稳定之后衔接：** [持久化数据](persist-data.md#persist-data)
- **继续 Cookie 支撑的登录态：** [Session](../session/README.md) · [HTTP Cookies](../http/cookies.md)
- **继续 Bearer 令牌校验：** [JWT](../jwt/README.md)
- **从认证流发送欢迎、找回或账户通知邮件：** [发送事务性邮件](send-transactional-email.md#send-transactional-email)
- **为登录或公开认证相关端点防滥用：** [用速率限制保护端点](protect-endpoints-with-rate-limits.md#protect-endpoints-with-rate-limits)
- **下一步设计角色、权限、所有权检查与服务级策略：** [添加授权](add-authorization.md#add-authorization)
- **在发布契约前保护登录、当前用户与受保护端点行为：** [为已有应用添加测试](add-testing-to-a-working-app.md#add-testing-to-a-working-app)
- **为客户端发布登录、当前用户与受保护端点契约：** [添加 API 文档](add-api-docs.md#add-api-docs)· [OpenAPI](../openapi/README.md)
- **保护端点与服务内检查：** [Authz](../authz/README.md)
- **正确放置请求级认证钩子：** [HTTP](../http/README.md) · [理解请求生命周期](../concepts/request-lifecycle.md)
- **在身份差异明确后重新审视缓存：** [添加缓存](add-caching.md#add-caching)· [HTTP Cache](../http-cache/README.md)
