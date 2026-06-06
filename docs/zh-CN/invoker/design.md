---
sidebar_label: Invoker 设计说明
description: 参数构建与受管 invocation 分离；钩子挂在 Di Invoker 外圈。
---

# 设计说明 {#design-rationale}

`switon/invoker` 在 `Switon\Di\Invoker` 外增加 guard/interceptor，使 **DI callable 路径**与**钩子路径**可分开理解。

## 参见 {#see-also}

- [Invoker 包](README.md#invoker-package) — 用法、边界与配置
- [FAQ](faq.md#invoker-faq) — 短答与排错
- [Invocation 包](../invocation/README.md#invocation-package) — guard 与 interceptor 契约

---

## 要解决的问题 {#core-problem}

调度代码常把两件事混在一起：

- 从传输层或 builder **构建**最终参数列表；
- 在 DI 回退解析的同时用 guard/interceptor **包裹** callable 执行。

若由一个包全包，边界难讲清，也难复用。

## 首要目标 {#primary-goal}

在 DI callable 执行步骤外标准化方法级钩子：

- **好处：** 不同传输共享同一最终调用路径。
- **好处：** guard 与 interceptor 附在 invocation 上，而非附在传输解析上。
- **代价：** 实参构造仍属独立包。

## 核心选型 {#core-choices}

- **先定 callable 边界：** invocation 开始前调用点已知目标；
- **小步最终解析：** 显式覆盖、默认、类型键、DI 回退集中一处；
- **受管 guard / interceptor：** 不属于普通直接方法调用。

## 取舍 {#trade-offs}

- 直接调用更简单，但绕过钩子；
- 传输 binding 外移，invocation 路径更易推理；
- 构造函数调用**不**视为受管 invocation 目标。
