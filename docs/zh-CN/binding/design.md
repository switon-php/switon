---
sidebar_label: Binding 设计说明
description: 为何拆分 binding 与 invoker、标量/对象分流、类型自持 Hydration。
---

# 设计说明 {#design-rationale}

`switon/binding` 把「**参数值如何从环境里长出来**」与「**可调用如何被执行**」拆开，各自保持单一职责。

## 参见 {#see-also}

- [Binding 包](README.md) — 用法与边界
- [FAQ](faq.md#binding-faq) — 常见问题

---

## 为何需要这一层 {#why-this-exists}

入口代码往往先确定**目标方法**，再补齐**最终实参**。

Binding 固化三类反复出现的决策：

- **类型自持规则：** 通过 **`#[ResolvedBy(...)]`** 固定在具体类型上
- **服务式对象：** 其它路径都没命中时由**容器**提供
- **标量：** 统一走 **`Switon\Core\InputInterface`**

## 与 Invoker 分立 {#separate-invoker}

- **`switon/binding`：** 产出解析后的 **实参列表**
- **`switon/invoking`（`InvokerInterface`）：** 用该列表执行调用

**收益：** 来源优先级、Hydration、校验不与「怎么 invoke」的执行策略绞在一起。

## 标量与对象分流 {#scalars-objects-separate}

标量参数与对象参数走**不同**解析链路。

**收益：** 避免原始输入在未显式绑定时「漏进」对象构造。

## 类型自持 {#type-owned-resolution}

部分值模型的构造契约有足够稳定性，应由**类型自己声明**。

**例：`Page`**

- 规则通过 **`#[ResolvedBy(...)]`** 附着在类型上
- 调用方不必在每个参数上重复解析细节

## 类型化 Hydration {#input-lives-here}

类型化绑定是跨 HTTP 等场景的**可复用机制**：

- 传输层只决定**拿哪块负载**
- Binding 负责**填充对象并校验**

## 钩子 {#why-hooks-exist}

横切改写实参不该靠复制一整段派发代码。

仅在行为**横跨多个解析器**时，才使用前置填充与最终调整钩子。

## `InputInterface` 的归属 {#input-interface-outside}

Binding **消费** `Switon\Core\InputInterface`，但**不负责**定义传输语义。

HTTP、CLI 与其它入口可以共用同一套 binding，正因这一契约与传输无关。
