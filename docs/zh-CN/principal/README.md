---
sidebar_label: Principal 包
description: switon/principal：IdentityInterface/TenantInterface 承载已解析的身份与租户状态。
---

# Principal 包 {#principal-package}

`switon/principal` 在边界代码**已解析**身份与租户之后，为 Switon 应用提供**当前身份**与**当前租户**的单一运行时契约。适用于业务代码需要以统一形态读取已解析的调用方与租户，而**不**依赖 JWT payload、会话或 Host/Header 解析的场景。本包**不**认证用户，也**不**发现租户。

**关键特性：**

- **身份契约：** `Switon\Principal\IdentityInterface` 承载当前调用方状态（id、name、roles）。
- **租户契约：** `Switon\Principal\TenantInterface` 承载当前租户状态（id、code）。
- **访客与无租户：** 调用方可在不猜测「是否已解析」的情况下读取当前状态。
- **规范化数据：** 业务代码读稳定类型，而非各来源专有 payload。
- **边界友好：** 认证与租户查找留在本包之外。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+、`switon/core`

```bash
composer require switon/principal
```

底层状态存放在 Switon 的 context 对象中，因而随当前请求或协程运行时迁移，而**不是**进程级单例。

---

## 快速开始 {#quick-start}

在应用代码需要当前调用方或租户处，注入运行时契约。

```php
namespace App\Controller;

use Switon\Core\Attribute\Autowired;
use Switon\Principal\IdentityInterface;
use Switon\Principal\TenantInterface;

final class ProfileController
{
    #[Autowired] protected IdentityInterface $identity;
    #[Autowired] protected TenantInterface $tenant;

    public function indexAction(): array
    {
        return [
            'user_id'     => $this->identity->isGuest() ? null : $this->identity->getId(),
            'roles'       => $this->identity->getRoles(),
            'tenant_code' => $this->tenant->hasTenant() ? $this->tenant->getCode() : null,
        ];
    }
}
```

边界代码（认证过滤器、租户解析、JWT 处理等）在解析完成后调用 `set()`。应用代码**只读**契约。

---

## 核心概念 {#core-concepts}

### 身份 {#identity}

`Switon\Principal\IdentityInterface` 在认证**已解析**后承载当前调用方。

- `isGuest()` 报告是否已存储任何调用方。
- 访客状态下 `getId()` 与 `getName()` 抛出 `NotAuthenticatedException`。
- 访客状态下 `getRoles()` 安全，返回 `[]`。
- `set($id, $name, $roles)` 存储已解析状态；roles 经 trim 并规范化为 `list<string>`。
- `clear()` 将契约恢复为访客状态。

### 租户 {#tenant}

`Switon\Principal\TenantInterface` 在其它边界**已解析**后承载当前租户。

- `hasTenant()` 报告是否已存储租户。
- 无可用租户时 `getId()` 与 `getCode()` 抛出 `TenantNotAvailableException`。
- `set($id, $code)` 存储已解析状态；code 在存储前经 trim。
- `clear()` 将契约恢复为无租户状态。

若应用只使用一种租户标识符，`id` 与 `code` 可携带相同值。

### 职责范围 {#scope}

本包拥有**消费侧**「已解析主体状态」契约，**不**拥有：

- 认证协议或会话/令牌解析；
- 租户发现规则或 Host/Header 路由；
- 用户或租户的持久化模型。

范围刻意收窄：契约描述**共享使用边界**，而非背后的来源系统，因而保持稳定。

---

## 使用注意 {#keep-in-mind}

- 在**拥有**身份与租户的边界处完成解析；本包**只**承载结果。
- 应用代码需要当前主体状态时注入 `IdentityInterface` 与 `TenantInterface`。
- 请求运行时需要回到访客或无租户状态时调用 `clear()`。
- 身份与租户契约**相互独立**，可同时持有不同取值。

---

## 下一步 {#next}

- **常见问题：** [FAQ](faq.md#principal-faq)
- **设计边界：** [设计说明](design.md#design-rationale)
- **按角色落实权限：** [授权包](../authz/README.md)
- **设置身份前校验令牌：** [JWT 包](../jwt/README.md)
