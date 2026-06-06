---
description: 认证已可用时，为端点放置访问规则、所有权检查与业务权限：Handler 属性、服务检查、Lookup 与 Voter 的最小路径。
---

# 添加授权 {#add-authorization}

当认证已经可用，而一条可用的 HTTP 或 service 路径现在需要明确的访问规则、所有权检查或业务权限，且你不希望过早把 RBAC 产品设计、管理后台 UI 或外部策略引擎当作首要答案时，使用本指南。

## 选择第一种授权边界 {#choose-the-first-authorization-boundary}

从与真实决策匹配的最小边界起步。

| 边界 | 最适合 | 注意 |
|---|---|---|
| 单个 action 上的 `#[Authorize(...)]` | 一个端点应为公开、仅已登录，或限于一个显而易见的业务角色 | 规则应靠近 handler 且在评审中可见时，这是最佳首步 |
| 类级 `#[Authorize(...)]` 并用方法覆盖 | 一整族控制器共享同一默认策略，少数例外 | 方法级 attribute 覆盖类级默认 |
| `AuthorizationInterface::can()` / `authorize()` | service 代码、worker、命令或领域编排必须执行或分支权限 | 已知稳定 handler-id 码（如 `admin::refund-order`）时优先传入 |
| `LookupInterface` 映射 | 业务角色应授予精确权限码，而非仅直接匹配控制器 attribute | Lookup 为精确匹配；CSV 权限码逗号分隔、**无空格** |
| `VoterInterface` | 访问依赖所有权、租户、IP 或其他上下文，角色 alone 无法表达 | 第一个非 null 的 voter 结果生效，且先于内置角色行为运行 |

一种常见应用形态是：

- `#[Authorize(Authorize::AUTHENTICATED)]` 表示「仅已登录」路径
- 在明显特权 handler 上使用一个业务角色如 `admin`
- 当 service 检查需要精确权限码时引入 `LookupInterface`
- 仅当上下文（如所有权或租户成员）成为真实规则时增加一个 voter

## 推荐构建顺序 {#recommended-build-order}

### 1. 在身份加载已经真实之后再开始 {#build-order-auth-first}

当某条请求路径已能可靠设置 `IdentityInterface` 时，授权会容易得多。
不要用 Authz 文档去先调试缺失的 session、令牌或当前用户接线。

### 2. 用 `#[Authorize]` 保护一个显而易见的 handler {#build-order-attribute-first}

从你能用一句话说清的端点起步：

- 登录或注册应保持 `ANONYMOUS`
- 当前用户或账户页应为 `AUTHENTICATED`
- 明显特权的管理端点可用一个业务角色如 `'admin'`

这条首规则在扩展到更广权限目录之前，先证明 handler 边界。
若应用有全局路由前缀如 `/api`，控制器映射保持无前缀，并在调试 `401` 或 `403` 之前用 `bash bin/console router:prefix` 确认真实公开 URL。

### 3. 把身份状态标记与业务角色分开 {#build-order-markers}

`Authorize::ANONYMOUS` 与 `Authorize::AUTHENTICATED` 仅作身份状态标记。
自定义字符串如 `'admin'` 或 `'editor'` 用于业务访问。

这样把「谁已登录？」与「他们能做什么？」分开。

### 4. 把属于 service 的决策移到 `AuthorizationInterface` {#build-order-service-checks}

当真实决策发生在 service 内部时，不要把它藏在仅控制器的 attribute 后面。
注入 `AuthorizationInterface` 并：

- 拒绝只是业务逻辑中的普通分支时用 `can()`
- 拒绝应成为 HTTP 结果并发出 authz 事件时用 `authorize()`

已知稳定 handler-id 码如 `admin::refund-order` 或 `rbac.role::index` 时优先使用。
仅当你有意要 handler 元数据解析时，才传入真实的 `FQCN::method`。

### 5. 仅在直接角色不再可扩展时添加 `LookupInterface` {#build-order-lookup}

前几个端点的直接自定义角色通常足够。
当一个业务角色应在多个 handler 或 service 检查上授予多个精确权限码时，再添加 `LookupInterface`。

保持权限码精确且明确：

- `admin::refund-order`
- `catalog.product::publish`
- `rbac.role::edit`

不要依赖运行时检查中的通配符自动展开。

### 6. 仅为真实上下文规则添加 voter {#build-order-voter}

当访问依赖的不止角色与权限码时，voter 才是合适工具。
典型例子：

- 资源所有权
- 租户隔离
- IP 白名单
- 环境或网络限制

保持 voter 窄而专注。
让它回答上下文问题，而不是取代整套权限模型。

### 7. 在 RBAC 平台设计之前停下 {#build-order-stop}

第一次授权胜利通常很小：

- 少量清晰的 handler attribute
- 少量稳定权限码
- 也许一个上下文 voter

在第一条应用路径感觉正确之前，**不需要**权限表、管理 UI、导入/导出流或策略 DSL。

## 最小接线 {#minimal-wiring}

下面只用短代码展示 handler、service 检查、Lookup 映射与上下文 voter 之间的接缝。
下文应用名仅为示例；稳定部分是 Switon 授权表面。

### 用可见规则保护 handler {#minimal-wiring-handler-rules}

从易于阅读的控制器级规则起步：

```php
namespace App\Controller;

use Switon\Authorizing\Attribute\Authorize;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;

#[Authorize(Authorize::AUTHENTICATED)]
#[RequestMapping('')]
class AdminOrderController
{
    #[GetMapping('/admin/orders')]
    public function indexAction(): array
    {
        return ['ok' => true];
    }

    #[PostMapping('/admin/orders/{id}/refund')]
    #[Authorize('admin')]
    public function refundAction(int $id): array
    {
        return ['refunded' => $id];
    }
}
```

当方法级例外应覆盖控制器级默认时，这是最佳首步。
若应用加了 `/api` 等全局前缀，公开路径为 `/api/admin/orders` 与 `/api/admin/orders/{id}/refund`，尽管控制器映射仍无前缀。

### 把属于 service 的权限检查放在 `AuthorizationInterface` {#minimal-wiring-service-checks}

当决策属于业务代码时使用程序化检查：

```php
namespace App\Service;

use Switon\Authorizing\AuthorizationInterface;
use Switon\Core\Attribute\Autowired;

class RefundOrderService
{
    #[Autowired] protected AuthorizationInterface $authorization;

    public function refund(int $orderId): void
    {
        $this->authorization->authorize('admin::refund-order');

        // ... refund logic ...
    }

    public function canViewAuditLog(): bool
    {
        return $this->authorization->can('admin::audit-log');
    }
}
```

在请求边界或类边界式的 service 入口用 `authorize()`。
当结果应保留为普通应用分支时用 `can()`。

### 把业务角色映射到精确权限码 {#minimal-wiring-lookup}

当一个角色应授予多个显式权限时添加 Lookup 映射：

```yaml
# switon.yml
Switon\Authorizing\LookupInterface:
  permissions:
    admin: admin::refund-order,admin::audit-log
    editor: catalog.article::publish,catalog.article::archive
```

CSV 保持精确紧凑。
权限码之间**不要**加空格。

### 为所有权或租户规则添加一个上下文 voter {#minimal-wiring-voter}

当仅靠角色不够时使用 voter：

```yaml
# switon.yml
Switon\Authorizing\AuthorizationInterface:
  voters:
    - App\Auth\OwnershipVoter
```

```php
namespace App\Auth;

use ReflectionMethod;
use Switon\Authorizing\VoterInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Principal\IdentityInterface;
use Switon\Http\RequestInterface;

class OwnershipVoter implements VoterInterface
{
    #[Autowired] protected IdentityInterface $identity;
    #[Autowired] protected RequestInterface $request;

    public function vote(string $permission, ?ReflectionMethod $handler): ?bool
    {
        if ($permission !== 'blog.post::edit') {
            return null;
        }

        return (string) $this->request->get('author_id') === (string) $this->identity->getId();
    }
}
```

这样所有权规则既明确又依赖上下文。
若 voter 不适用，返回 `null`，让默认 authz 流继续。

### 把 `PermissionsInterface` 用于工具，而非运行时授予 {#minimal-wiring-catalog}

若日后需要目录或 RBAC UI 支持，把 `PermissionsInterface` 当作工具表面：

```php
namespace App\Command;

use Switon\Authorizing\PermissionsInterface;
use Switon\Core\Attribute\Autowired;

class DumpPermissionCatalogCommand
{
    #[Autowired] protected PermissionsInterface $permissions;

    public function defaultAction(): array
    {
        return $this->permissions->flatten($this->permissions->scanCatalog());
    }
}
```

该目录仅反映已路由控制器上的 `#[Authorize]` 元数据。
对导出、诊断与管理工具有用，但在涉及 lookup 提供方或 voter 时**不是**合并后的运行时真相。

## 首条路径上宜避免什么 {#what-to-avoid-first}

有意让第一条授权路径保持窄。

下列内容不宜过早：

- **把授权当成认证** — `Authz` 消费 `IdentityInterface`；它不会自行创建登录态
- **把空 `#[Authorize]` 当作公开访问** — 它不会授予访客访问；请显式使用 `Authorize::ANONYMOUS`
- **把 `AUTHENTICATED` 或 `ANONYMOUS` 与自定义角色混用** — 身份状态标记在 `#[Authorize]` 中必须单独出现
- **假设运行时通配符展开** — Lookup 与 service 检查匹配精确字符串，不会即时展开 `controller::*` 模式
- **在 `LookupInterface` CSV 配置中加空格** — 运行时匹配精确，空白有意义
- **把属于 service 的权限决策埋在控制器里** — 真实边界在业务逻辑中时，用 `AuthorizationInterface`
- **把 `PermissionsInterface::scanCatalog()` 当作运行时真相** — 它不包含外部 lookup 来源或 voter 结果
- **直接跳到完整 RBAC 产品设计** — 先证明一条清晰、应用自有的权限路径

完成第一条授权路径后，初学者应能感到：

> 我知道哪些规则属于 handler、哪些属于 service、何时值得做 Lookup 映射，以及何时 voter 才是对的逃生舱。

## 继续深入 {#go-deeper}

- **在策略设计之前完成身份加载：** [添加认证](add-authentication.md)
- **停留在控制器级或 action 级授权规则：** [Authz](../authz/README.md) · [Controller](../http/controller.md#controller)
- **在 service 代码中保持业务权限明确：** [Authz 程序化检查](../authz/README.md#service-checks)
- **添加配置或数据库支撑的权限 Lookup：** [Authz 权限 Lookup](../authz/README.md#permission-lookup)
- **添加上下文所有权、租户或 IP 决策：** [Authz voter](../authz/README.md#voters)
- **构建权限目录或 RBAC UI 视图：** [Authz FAQ](../authz/faq.md)
- **测试受保护 handler 与 `401` / `403` 边界：** [为已有应用添加测试](add-testing-to-a-working-app.md#add-testing-to-a-working-app)
- **为客户端文档化受保护端点与拒绝行为：** [添加 API 文档](add-api-docs.md)
- **在 authz 策略之外单独防滥用保护认证相关端点：** [用速率限制保护端点](protect-endpoints-with-rate-limits.md)
