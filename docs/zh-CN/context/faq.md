---
sidebar_label: Context FAQ
description: Context：何时用、Core 注入、返回类型、ContextIsolated、resetContexts。
---

# Context FAQ {#context-faq}

**常见问题速答。**

> **前置：** [Context 包](README.md#context-package)

## 入门 {#getting-started}

### 什么时候该用 Context？ {#use-context}

当一个**长生命周期服务**在多个方法间持有可变状态，且该状态必须随**当前请求或协程作用域**走时，用 Context。若只是一步递值，通常请求 attribute 更简单。

参见 [Context 包](README.md#context-package) 与 [HTTP 请求](../http/request.md#request)。

### 为什么要注入 `Switon\Core\ContextManagerInterface`？ {#inject-core-interface}

应用层只依赖 **Core 契约**；`switon/context` 在启动时挂上默认实现，服务不必绑死在具体实现类上。

详见 [配置](README.md#configuration)。

### `getContext()` 必须返回什么？ {#context-return-type}

返回类型必须指向**可加载**的 Context 类，且该类**无必填构造参数**。不合法会抛 `Switon\Context\Exception\ContextException`。

详见 [Context 类规则](README.md#context-class-rules)。

### 何时用 `ContextIsolated`？ {#use-contextisolated}

当子协程**不能**与父级共享临时或资源绑定状态（例如事务、草稿缓冲）时使用。不用时，子协程会继承父级 Context。

详见 [标记契约](README.md#marker-contracts)。

### `resetContexts()` 清什么？ {#reset-contexts}

只清当前 manager 上的**非协程**缓存。常驻 worker 若要避免跨请求复用错误状态，仍须在合适时机调用。

参见 [使用注意](README.md#keep-in-mind) 与 [设计说明](design.md#design-rationale)。
