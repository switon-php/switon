---
sidebar_label: Throttle 包
description: switon/throttle：#[RateLimit]、Redis 计数、hit() 与 burst。
---

# Throttle 包 {#throttle-package}

`switon/throttle` 基于 **Redis** 为 HTTP 动作与直接应用检查提供限速。

**关键特性：**

- **控制器或动作上的 `#[RateLimit]`** — 限制贴近所保护的端点。
- **共享 Redis 计数器** — 跨 worker 与实例保持一致。
- **直接 `hit()` 检查** — 在队列、CLI 任务或服务代码中复用同一限流器。
- **多时间窗口** — 配置的每个窗口都须通过。
- **首条规则可选 burst** — 允许短时尖峰，而无需改动每条规则。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+，以及提供 HTTP、身份、事件与 Redis 的框架组件（`switon/http`、`switon/principal`、`switon/event`、`switon/redis`）。

```bash
composer require switon/throttle
```

---

## 快速开始 {#quick-start}

在要保护的控制器或动作上添加 `#[RateLimit]`。

```php
use Switon\Throttle\Attribute\RateLimit;

#[RateLimit('10/m')]
final class ApiController
{
    public function search(): array
    {
        return [];
    }
}
```

超出限制时抛出 `Switon\Throttle\Exception\RateLimitExceededException`，在常规请求流程中表现为 HTTP **429**。

---

## 核心概念 {#core-concepts}

### 限制规则 {#limits}

使用 `limit/period` 或 bare `limit`。

- `10/m` — 每分钟 10 次；
- `100/h` — 每小时 100 次；
- `10` — 每 60 秒 10 次。

可传入一条或多条规则；**每条**都必须通过。

### 属性作用域 {#attribute-scope}

`#[RateLimit]` 可放在类或方法上。

- 方法级规则覆盖类级规则；
- 若无属性，可应用配置中的回退 `limits`；
- `burst` 从同一属性或回退配置读取。

### 手动检查 {#manual-checks}

当限速属于自有代码路径时，使用 `RateLimiterInterface::hit()`。

```php
use Switon\Core\Attribute\Autowired;
use Switon\Throttle\RateLimiterInterface;

final class SmsService
{
    #[Autowired] protected RateLimiterInterface $rateLimiter;

    public function send(string $phone): void
    {
        $this->rateLimiter->hit('sms:' . $phone, '5/m');
    }
}
```

### 身份维度 {#identity}

对自动 HTTP 限速，限流器对访客与已认证请求使用不同键。

- 访客请求使用客户端 IP；
- 已认证请求使用 `IdentityInterface::getName()`。

### 键 {#keys}

限流器键还包含控制器、动作与规则窗口。

- 自定义 `prefix` 按原样加在属性驱动键之前；
- 每条配置规则会在键后追加自己的 `:{limit}/{period}` 后缀。

### Burst {#burst}

`burst` **仅**作用于规则列表中的**第一条**。

在保持常规路径简单的同时，仍允许在最紧迫窗口上短时尖峰。burst 路径根据内部参考计数跟踪节奏，而非简单预加固定请求数。

---

## 配置 {#configuration}

可仅依赖属性运行；若需要默认值、自定义前缀或临时关闭，再增加配置。

```yaml
# switon.yml
Switon\Throttle\RateLimiterInterface:
  enabled: true
  prefix: cache:my-app:rate_limit:
  limits: ['60/m']
  burst: 20
```

常见选项：

- `enabled` 打开或关闭自动请求校验监听器；
- `prefix` 设置属性驱动限制的 Redis 键前缀；
- `limits` 在无 `#[RateLimit]` 时作为回退规则；
- `burst` 为**第一条**规则设置回退 burst 值。

自动限速挂钩在 `RequestValidating` 上；仅当限流器已启用时，`ServiceProvider::boot()` 才会调用 `RateLimiterInterface::boot()`。

### 选项发现 {#option-discovery}

```bash
bash bin/console config:options 'Switon\Throttle\RateLimiterInterface'
```

Throttle 亦依赖 `Switon\Redis\ClientInterface`。

---

## 使用注意 {#keep-in-mind}

- `enabled: false` **只**关闭自动请求校验。
- `prefix` **不**作用于手动 `hit()` 的键。
- `burst` **只**影响**第一条**配置规则。
- 本包**不会**自行添加响应头。
- 本包**不是**配额策略引擎。
- `#[RateLimit]` 对每个方法/类只读取一次；多个属性**不会**合并。

---

## 下一步 {#next}

- **快速答疑：** [FAQ](faq.md#throttle-faq)
- **设计取舍：** [设计说明](design.md#design-rationale)
- **端点防护 walkthrough：** [用速率限制保护端点](../guides/protect-endpoints-with-rate-limits.md#protect-endpoints-with-rate-limits)
- **Redis：** [Redis 包](../redis/README.md)
