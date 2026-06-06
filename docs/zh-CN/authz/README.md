---
sidebar_label: 授权包
description: Switon 授权包（switon/authz）：权限码、#[Authorize]、Lookup、Voter 与运行时鉴权。
---

# 授权包 {#authorization-package}

`switon/authz` 为控制器和服务提供一套共用的、基于**权限码**的授权模型。业务角色来自 `Switon\Principal\IdentityInterface::getRoles()`；**谁算已登录**仍由身份层负责，本包只处理**授权**。

**关键能力：**

- **访问规则：** 在控制器类或方法上使用 `#[Authorize]`。
- **同一套 API：** 服务里注入 `AuthorizationInterface` 进行判断。
- **身份态：** `Authorize::ANONYMOUS`、`Authorize::AUTHENTICATED` 区分访客与已登录。
- **超级用户：** `Authorize::SUPERUSER` 是内置特权角色键；身份里带它时，只要前面的 Voter 没先否决，就会跳过后续默认检查（Voter 先跑，见下文）。
- **角色到权限：** 用 `LookupInterface` 把应用角色映射成权限码。
- **看场景再判：** 涉及请求或领域上下文时用 `VoterInterface`。
- **决策事件：** 允许或拒绝时都会派发事件。

## 安装 {#installation}

**环境要求：** PHP 8.3+

```bash
composer require switon/authz
```

## 快速开始 {#quick-start}

控制器上加 `#[Authorize]`，服务里用 `AuthorizationInterface`。

```php
use Switon\Authorizing\Attribute\Authorize;
use Switon\Authorizing\AuthorizationInterface;
use Switon\Core\Attribute\Autowired;

#[Authorize(Authorize::AUTHENTICATED)]
class AccountController
{
    #[Authorize(Authorize::ANONYMOUS)]
    public function loginAction(): array
    {
        return ['ok' => true];
    }

    public function profileAction(): array
    {
        return ['ok' => true];
    }
}

class OrderService
{
    #[Autowired] protected AuthorizationInterface $authorization;

    public function cancel(int $orderId): void
    {
        $this->authorization->authorize('order::cancel');
    }
}
```

`can()` 只返回是否允许；`authorize()` 会在拒绝时抛出 HTTP 层异常，并带上事件。

## 核心概念 {#core-concepts}

### 属性怎么写 {#attribute-first-rules}

- 类上写 `#[Authorize]` 当默认值；某个方法要例外，就在方法上再写一层，**只盖这一条**。
- 方法级属性覆盖类级默认。
- 若某个参数是**权限引用**（字符串以 `@` 开头，例如 `@edit`），鉴权侧会把它记成引用元数据，而不是当成一串角色名。
- `assignable` 只影响**扫描、目录、RBAC 界面**那一块的元数据，**不参与**运行时放行与否。
- `admin::create-user` 这类字符串是**权限码**，控制器和服务里都用同一套命名。
- 必须指到**某一个控制器方法**时，用完整 `FQCN::method`。
- 只写 `#[Authorize]` 且不传参数时，方法仍在鉴权流水线里：已登录用户会继续走权限解析；访客在解析前就会被拒（详见 [FAQ](faq.md#empty-authorize)）。
- `@` 开头的引用主要方便扫描和目录；真正放行仍要看处理器 id、类/方法上的属性，以及 Lookup 的常规定位。

### 身份态 {#identity-states}

- `Authorize::ANONYMOUS`：公开。
- `Authorize::AUTHENTICATED`：必须已登录（任意有效身份即可进入后续判断）。

### 超级用户 {#superuser-role}

- `Authorize::SUPERUSER` 表示**特权角色键**。
- 身份角色里带上它，且前面的 Voter 没有先下否定票时，会走**全局短路**，后面的默认步骤不再挡。

### 服务里怎么用 {#service-checks}

- 拒绝只是分支逻辑：用 `AuthorizationInterface::can()`。
- 拒绝要变成 `401` / `403`：用 `AuthorizationInterface::authorize()`。
- 调用时可传入角色列表，**临时覆盖** `IdentityInterface::getRoles()` 的返回值。
- `authorize()` 成功会发 `AuthorizationGranted`，失败会发 `AuthorizationDenied`。

### 角色映射到权限 {#permission-lookup}

用 `LookupInterface` 做**角色 → 权限码**。

- 权限码和运行时字符串要**完全一致**。
- 映射里不要夹空格。
- 不要把 `anonymous`、`authenticated` 写进 Lookup，那是身份态，不是业务角色表。

### Voter {#voters}

适合归属、租户、IP 这类要读请求或上下文的判断。

- `true`：放行，后面 Voter 不再问。
- `false`：拒绝，也不再问后面。
- `null`：弃权，交给默认逻辑（含超级用户分支）。
- **第一个非 null 结果说了算**。
- Voter **早于**内置按角色放行的逻辑（含 `SUPERUSER`），所以 Voter 可以直接把超级用户也挡掉。

```php
use ReflectionMethod;
use Switon\Authorizing\VoterInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Http\RequestInterface;

class IpAllowlistVoter implements VoterInterface
{
    #[Autowired] protected RequestInterface $request;

    public function vote(string $permission, ?ReflectionMethod $handler): ?bool
    {
        if (!str_starts_with($permission, 'admin::')) {
            return null;
        }

        if ($this->request->ip() !== '127.0.0.1') {
            return false;
        }

        return null;
    }
}
```

## 配置 {#configuration}

### 角色到权限 {#role-mapping}

```yaml
# switon.yml
Switon\Authorizing\LookupInterface:
  permissions:
    admin: admin::create-user,admin::delete-user
    rbac-manager: rbac.role::index,rbac.role::edit
```

权限来自数据库、缓存或外部策略时，直接自己实现 `LookupInterface` 即可。

### 注册 Voter {#voters-config}

```yaml
# switon.yml
Switon\Authorizing\AuthorizationInterface:
  voters:
    - App\Auth\IpAllowlistVoter
```

按列表顺序执行；`true` / `false` 结束链条，`null` 继续走默认检查。

## 使用注意 {#keep-in-mind}

- 不写角色列表的 `#[Authorize]` **不是**公开接口；要公开就写 `Authorize::ANONYMOUS`。
- `Authorize::AUTHENTICATED` 表示**已登录**，不是业务角色名。
- 需要强制执行和事件：用 `authorize()`；只要分支：用 `can()`。
- 默认顺序：**Voter → 身份是否带 `SUPERUSER` → 处理器上的属性 → Lookup**。
- HTTP 请求会由 `Switon\Http\Event\RequestAuthorizing` 自动鉴权；离开控制器流水线后再直接调 `authorize()`。
- Lookup 里的权限码不要带空格。

## 下一步 {#next}

- **补授权：** [添加授权](../guides/add-authorization.md#add-authorization)
- **补认证：** [添加认证](../guides/add-authentication.md#add-authentication)
- **常见问题：** [FAQ](faq.md#authorization-faq)
- **设计说明：** [设计说明](design.md#design-rationale)
