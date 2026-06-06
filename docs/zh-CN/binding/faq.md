---
sidebar_label: Binding FAQ
description: Binding：与 Invoker 分工、参数来源、ResolvedBy、测试与边界情况。
---

# Binding FAQ {#binding-faq}

安装、配置与常见绑定问题的短答。

> **前置：** [Binding 包](README.md#binding-package)

## 入门 {#getting-started}

### Binding 和 Invoker 有什么不同？ {#binding-vs-invoker}

**Binding** 负责**攒出实参列表**。**Invoker** 负责**执行**可调用目标。

### 我已经知道要调哪个方法，还要 Binding 吗？ {#already-know-callable}

只有当你仍希望框架（或测试夹具）根据输入、构造器、`InputInterface`、容器或**默认值**去**自动拼装实参**时才需要。

### 参数值都从哪来？ {#parameter-sources}

- 前置填充钩子
- `ArgumentResolvable`
- `#[ResolvedBy]`
- 仍未解析的对象走**容器**
- **标量**走 `InputInterface`
- 默认值与校验链路

补充：`#[ShortOption]` 属于 **CLI 选项解析**，不是 `switon/binding`。`RequestBody` 等在 **HTTP 包**声明，Hydration 走 binding。

### 什么时候用 `InputBinderInterface`？ {#when-inputbinder}

某一解析阶段已经有一份 **数组负载**，需要一个**统一引擎**把多种类型输入对象填满时用。

## 构造器与解析器 {#builders-and-resolvers}

### 何时用 `#[ResolvedBy]`？ {#when-resolvedby}

当**同一个具体类型**总是按**同一套规则**构造出来时。

### 示例为何老用 `Page`？ {#why-page}

因为它是文档里最好懂的例子：**规则写在类型上、调用点不写一堆胶水**；你自己的值类型若是这种模式，通常也适合 `#[ResolvedBy]`。

### 怎样照抄 `Page` 模式？ {#copy-page-pattern}

类上加 `#[ResolvedBy(...)]`，实现 `ValueResolverInterface`，在解析器里读输入或服务，返回成品值对象。

### 何时用「类型自建」 (`ArgumentResolvable`)？ {#when-argumentresolvable}

当构造规则本应属于**值类型自身**而不是外部解析器时。

### 为什么我的对象最终还是容器注入的？ {#object-container}

类型自持路径没有命中：**接口类型**或缺少 **`#[ResolvedBy]`** 时会回落到容器。

### 想改一大片标量的来源策略怎么办？ {#change-scalar-sourcing}

在你确实需要 **应用级、横向**改动时再去换/扩默认标量策略，而不是每个 Action 打点。

## 排错 {#troubleshooting}

### 解析器能返回「最终值就是 null」吗？ {#resolver-null}

默认解析接口里 **`null` 表示未解析**。**`ArgumentResolvable`** 若在类型语义允许下可以表达「真正的 null」，则另当别论。

### 为什么不能用下标预填 `null`？ {#null-by-index}

按索引预设时初值就是 `null`，**「显式注入 null」与「_slot 空着」分不清**。需要传 `null` 时请用**参数名**。

### 为什么会报 positional 太多？ {#too-many-positional}

位置绑定不接受多余片段，除非**最后一个仍未解析的标量参数**类型为 `array`。

### 支持联合类型 / 交叉类型参数吗？ {#union-intersection}

不支持。`ArgumentsBinderInterface` 只处理常规的**命名单类型**参数。

### 绑定很多时怎么测？ {#testing}

业务逻辑优先**手写实参直连方法**。仅当「**实参如何从外界长出来**」本身就是被测对象时，才穿透 binding / HTTP / CLI 去测。
