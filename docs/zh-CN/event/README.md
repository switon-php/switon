---
sidebar_label: Event 包
description: switon/event：PSR-14、#[EventListener]、通配 object、优先级与 EventLogger。
---

# Event 包 {#event-package}

`switon/event` 为 Switon 提供基于类的**进程内同步**派发：属性发现的监听器、通配监听器，以及可选的自动事件日志。

**关键特性：**

- **PSR-14 入口：** 注入 `Psr\EventDispatcher\EventDispatcherInterface`，调用 `dispatch()`。
- **类即事件：** 类名标识事件，对象承载载荷。
- **属性发现：** `#[EventListener]` 从扫描路径或 Composer `extra.switon.listeners` 注册 public 监听方法。
- **通配监听：** 方法参数类型为 `object` 时对每个事件都会运行。
- **优先级：** `#[EventListener(priority: N)]` 数值越小越先执行。
- **可观测性拆分：** 实现 `ObservabilityProbe` 的监听器类与业务监听器分别计数。
- **内置事件日志：** `EventLogger` 以通配监听器身份注册，默认 `debug` 级别。

## 安装 {#installation}

**环境要求：** PHP 8.3+

```bash
composer require switon/event
```

默认：`Psr\EventDispatcher\EventDispatcherInterface` 解析到 `Switon\Eventing\EventDispatcherInterface`，`Psr\EventDispatcher\ListenerProviderInterface` 解析到 `Switon\Eventing\ListenerProviderInterface`。

## 快速开始 {#quick-start}

定义一个事件，派发它，并用一个监听器处理。

```php
namespace App\Event;

final class UserCreated
{
    public function __construct(
        public readonly int $userId,
        public readonly string $email,
    ) {
    }
}
```

```php
namespace App\Service;

use App\Event\UserCreated;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\Attribute\Autowired;

class UserService
{
    #[Autowired] protected EventDispatcherInterface $events;

    public function register(string $email): void
    {
        $this->events->dispatch(new UserCreated(123, $email));
    }
}
```

```php
namespace App\Listener;

use App\Event\UserCreated;
use Switon\Eventing\Attribute\EventListener;

class UserListener
{
    #[EventListener]
    public function onUserCreated(UserCreated $event): void
    {
        // react here
    }
}
```

默认会发现 `app/Listener/*Listener.php` 与 `app/Areas/*/Listener/*Listener.php` 下的监听器类。监听方法须为 public、带 `#[EventListener]`，且**恰好声明一个**参数，类型为具体事件类、`object`，或若干事件类的联合类型。无类型或交叉类型的监听方法会在注册阶段快速失败；零个或多于一个参数同样会在注册阶段快速失败。

`dispatch()` 在当前进程内同步运行监听器，并返回**同一个**事件对象。

## 术语速览 {#terms}

- `event class` —— 命名事件并承载载荷的一个 PHP 类
- `listener` —— 带 `#[EventListener]`、恰好一个类型化参数的 public 方法
- `wildcard listener` —— 参数类型为 `object` 的监听方法
- `observability listener` —— 实现了 `ObservabilityProbe` 的监听器类
- `StopFlow` —— 有意中止后续监听器
- `EventLogger` —— 可记录已派发事件的内置通配监听器

## 核心概念 {#core-concepts}

### 事件 {#events}

- 用普通 PHP 类；类名即身份，对象即载荷。
- 载荷保持显式、稳定；优先 ID、枚举、短摘要，少放大可变对象或敏感原文。

### 监听器 {#listeners}

监听方法是带 `#[EventListener]` 的 public 方法。

- 单一具体事件类、所有事件的 `object`，或事件类的联合类型。
- `#[EventListener(priority: N)]` 数值越小越先；同优先级维持注册顺序。
- 事件匹配只看**具体**事件类；**父类或接口类型接不住子类事件**。

### 创建与派发 {#create-and-dispatch}

- 先跑通配监听器，再跑针对具体事件的监听器。
- `dispatch()` 返回同一事件对象；监听器返回值被忽略。
- 监听器内抛出 `Switon\Core\StopFlow` 可有意中止后续监听。
- 若未接线 listener provider（例如极简引导），`dispatch()` 为空操作。

### 事件日志 {#event-logging}

`EventLogger` 自身即通配监听器；启用后通过绑定的 PSR-3 日志按 `debug` 级别记录每次派发。

- 事件类实现 `Switon\Eventing\EventSilent` 可跳过自动日志。
- 事件类加 `#[EventLevel(Severity::INFO)]`（或任意 `Switon\Eventing\Severity`）可改默认级别。
- 事件实现 `Switon\Eventing\EventLogInterface` 时可自定义日志格式；默认 `EventLogger` 会委托给它。
- `Switon\Eventing\EventWrapper` 默认序列化 public 标量属性；值为对象的属性除非事件实现 `JsonSerializable` 否则跳过。
- 监听器类实现 `Switon\Eventing\ObservabilityProbe` 会被标记，其调用与业务监听器分开计数。

## 配置 {#configuration}

- `Switon\Eventing\ListenerDiscoveryInterface.listeners` 控制应用扫描路径。默认 `@app/Listener/*Listener.php` 与 `@app/Areas/*/Listener/*Listener.php`；包内监听从 Composer `extra.switon.listeners` 来。
- `Switon\Eventing\EventLoggerInterface.enabled` 控制自动事件日志。设为 `false` 可关闭日志而不影响派发。

## 选项发现 {#option-discovery}

```bash
bash bin/console config:options 'Switon\Eventing\ListenerDiscoveryInterface'
bash bin/console config:options 'Switon\Eventing\EventLoggerInterface'
```

## 使用注意 {#keep-in-mind}

- `dispatch()` 是进程内同步的；异步或持久化工作请用队列或 RPC。
- 监听器返回值被忽略；需要结果请用服务或查询对象。
- 监听方法需要 public、`#[EventListener]`、恰好一个类型化参数，并经由发现路径或 Composer 元数据注册。

## 下一步 {#next}

- **注册与常见问题：** [FAQ](faq.md#event-faq)
- **为何保持同步且关注可观测性：** [设计说明](design.md#design-rationale)
- **与日志策略衔接：** [Log 包](../log/README.md)
