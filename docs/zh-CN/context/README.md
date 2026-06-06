---
sidebar_label: Context 包
description: switon/context：ContextAware、按请求/协程作用域的可变状态与 ContextIsolated。
---

# Context 包 {#context-package}

`switon/context` 把**归属于服务的可变状态**从共享的服务实例字段里挪出去。当服务需要随**当前 HTTP 请求**或**当前协程作用域**变化的数据时，用独立的 `*Context` 承载。

**关键特性：**

- **归属绑定：** 一个服务对应一颗专属的 `*Context`；
- **运行时感知存储：** 同一套服务代码在非协程与协程环境下都能跑；
- **子作用域：** 子协程默认继承父级 Context；需要隔离时用 `ContextIsolated`，并触发子作用域相关事件；
- **先 Core 契约：** 业务注入 `Switon\Core\ContextManagerInterface`；包在启动时挂上默认实现。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+

```bash
composer require switon/context
```

---

## 快速开始 {#quick-start}

1. 把必须随请求或协程走的可变字段，放进一个 `*Context` 类；
2. 让服务实现 `Switon\Core\ContextAware`，并注入 `Switon\Core\ContextManagerInterface`；
3. 通过 `getContext()` 读写，**不要**再把这类状态堆在服务自身属性上。

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Core\ContextAware;
use Switon\Core\ContextManagerInterface;

final class RequestStateContext
{
    public string $userId = '';
}

final class RequestState implements ContextAware
{
    #[Autowired] protected ContextManagerInterface $contextManager;

    public function getContext(): RequestStateContext
    {
        return $this->contextManager->getContext($this);
    }

    public function setUserId(string $userId): void
    {
        $this->getContext()->userId = $userId;
    }

    public function userId(): string
    {
        return $this->getContext()->userId;
    }
}
```

---

## 核心概念 {#core-concepts}

### 归属与状态 {#owner-bound-state}

Context 类放**可变字段**，服务类只放**行为**。若值必须随当前请求/协程重置，就不要直接写在服务的共享属性上。

### 作用域解析 {#scope-resolution}

`Switon\Core\ContextManagerInterface` 按「归属 + 当前运行时作用域」解析出唯一 Context。

- 非协程运行时：同一归属在当前 manager 实例下拿到同一 Context；
- 协程运行时：子协程默认复用已有父级 Context；
- 若已开启协程模式但当前尚未进入协程，则回退到 root 存储；
- 需要「子级独享」可变状态时，用 `ContextIsolated` 申请新的子 Context。

子作用域相关事件：

- `ChildContextInherited`
- `ChildContextCreated`
- `ChildContextPropagated`

### 标记契约 {#marker-contracts}

- `ContextAware` — 标明**谁**拥有这份状态；
- `ContextIsolated` — 标明 Context **不可**在子协程间与父级共享；
- `ContextConnScoped` — 连接生命周期相关；本包只**特殊处理** `ContextIsolated`。

### Context 类规则 {#context-class-rules}

- `getContext()` 的返回类型必须指向**可加载**的 Context 类；
- Context 类必须**零必填参数**即可实例化；
- 声明不合法会抛出 `Switon\Context\Exception\ContextException`。

---

## 配置 {#configuration}

本包**没有** `switon.yml` 块。

- 业务侧注入 `Switon\Core\ContextManagerInterface`；包提供默认实现；
- `Switon\Context\ContextManagerInterface` 供框架/测试辅助，**不是**应用日常面向的契约。

---

## 使用注意 {#keep-in-mind}

- 请求内可变状态进 Context，不要进服务单例字段；
- 有意共享的属性可用 `#[Communal]`；请求级可变仍走 Context；
- 仅在子协程**绝不能**与父级共享时才用 `ContextIsolated`；
- `resetContexts()` 只清**非协程**缓存；root 回退存储仍在；
- 只是一次性传递的值，优先考虑请求 attribute，不必造 Context 类。

---

## 下一步 {#next}

- **与请求 attribute 对比：** [HTTP 请求](../http/request.md#request)
- **传播与隔离细节：** [设计说明](design.md#design-rationale)
- **采纳时的常见问题：** [FAQ](faq.md#context-faq)
- **实现演变（协程存储）：** [Context 内部说明](internals/README.md)
