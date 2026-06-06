---
sidebar_label: Session 包
description: switon/session：请求内会话、Redis JSON 持久化、延迟写入与 read/write。
---

# Session 包 {#session-package}

`switon/session` 在请求之间保持小体量 HTTP 会话状态，并以 **JSON** 形式存入 **Redis**。适用于短期用户状态（认证、flash、轻偏好）；**不是**大对象存储，也**不能**替代持久化存储。

**关键特性：**

- **请求作用域状态：** 即使在长生命周期运行时，会话数据仍按请求隔离。
- **Redis 存储：** `Switon\Session\Session` 通过 `Switon\Redis\ClientInterface` 持久化 JSON 载荷。
- **延迟写入：** `get()` / `set()` 先更新请求内状态；持久化在临近响应结束、于 `HeadersSending` 上执行。
- **直访存储：** `read($sessionId)` 与 `write($sessionId, $data)` 按会话 id 操作，不经 cookie。
- **生命周期事件：** `SessionStart`、`SessionEnd`、`SessionCreate`、`SessionUpdate`、`SessionDestroy` 与 `SessionUnserializeFailed`。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+，以及提供 HTTP 头、Redis 存储与事件的框架组件（`switon/http`、`switon/redis`、`switon/event`）。

```bash
composer require switon/session
```

---

## 快速开始 {#quick-start}

注入 `Switon\Session\SessionInterface`，使用请求作用域 API。

```php
namespace App\Controller;

use Switon\Core\Attribute\Autowired;
use Switon\Session\SessionInterface;

final class AuthController
{
    #[Autowired] protected SessionInterface $session;

    public function login(int $userId): void
    {
        $this->session->set('user_id', $userId);
    }

    public function me(): ?int
    {
        return $this->session->get('user_id');
    }

    public function logout(): void
    {
        $this->session->destroy();
    }
}
```

---

## 核心概念 {#core-concepts}

### 请求模型 {#request-model}

会话数据位于当前请求上下文中。`get()`、`set()`、`has()` 与 `remove()` 为常规请求 API；`has()` 遵循 `isset()` 语义，故将 `null` 视为缺失。若需子命名空间，可用 `Switon\Session\BagInterface` 在单个 session 键下分组相关键。

### 持久化 {#persistence}

`set()` 与 `remove()` 先更新请求内状态。持久化在 `Switon\Http\Event\HeadersSending` 事件期间、接近响应末尾执行，因此若在刷出响应头之前出错，**不会**留下半写数据。

`read($sessionId)` 与 `write($sessionId, $data)` 直接访问存储，**不**更新当前 cookie。

### 会话 ID {#session-ids}

- `getId()` 返回当前会话 id。
- `setId($sessionId)` 将当前请求切换到另一 id。
- `destroy()` 移除当前会话；`destroy($sessionId)` 移除一条已存储会话，**不**动当前 cookie。

---

## 配置 {#configuration}

多数应用只配置 cookie 名、TTL 与 lazy 窗口。

```yaml
Switon\Session\SessionInterface:
  name: PHPSESSID
  ttl: 3600
  lazy: 60
```

### 选项发现 {#option-discovery}

```bash
bash bin/console config:options 'Switon\Session\SessionInterface'
```

---

## 使用注意 {#keep-in-mind}

- `ttl` 控制存储寿命；cookie 寿命为 `params.expire`。
- `lazy > 0` 在载荷中维护内部 `__T` 时间戳以跳过未改数据的重复写入；`lazy = 0` 始终采用 touch-first 持久化。
- 存储载荷为 JSON，**不是** PHP `serialize()`；反序列化失败抛出 `SessionUnserializeFailed`，并向调用方暴露空数组。
- `write($sessionId, ...)` 继承当前请求的 TTL 覆盖。

---

## 下一步 {#next}

- **常见问题：** [FAQ](faq.md#session-faq)
- **设计取舍：** [设计说明](design.md#design-rationale)
- **Redis 细节：** [Redis 包](../redis/README.md)
- **认证选型：** [添加认证](../guides/add-authentication.md#add-authentication)
