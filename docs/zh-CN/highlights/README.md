---
description: Switon 框架亮点：身份感知安全、命名 Autowired 槽位与运行时安全的状态处理。
---

# Switon 亮点 {#switon-highlights}

当你想了解 Switon 在日常应用工作中有哪些框架级差异时，读这一节。

**关键特性：**

- **框架差异化** — 说明 Switon 试图标准化哪些反复出现的应用问题，而不是留给各团队自行约束
- **直达价值的短路径** — 在重复包参考细节之前，先说清机制为什么重要
- **跨包模式** — 涵盖横跨 `http`、`di`、`context`、`core` 与面向 ORM 的应用代码的思路
- **稳定的深度链接目标** — 为首页与对外功能简介提供每个主题一个规范落地页

## 为何存在本节 {#purpose}

部分 Switon 行为的重要性，与其说来自某个类或属性，不如说来自多个包围绕同一个应用问题的对齐。

当你的下一个问题不是“这个包支持什么配置键？”，而是“Switon 为什么要这样解决这个问题？”时，请使用 `highlights`。

---

## 当前亮点 {#current-highlights}

- **[身份感知的归属安全](identity-aware-ownership.md#identity-aware-ownership)** — `IdentityFilter`、实体绑定与统一的 `IdentityInterface` 如何让列表查询与单条记录操作处于同一归属边界
- **[Autowired 命名槽位](autowired-aliases.md#autowired-aliases)** — `#[Autowired]`、属性名槽位与 `Type#name` 服务 ID 如何让多实例依赖装配远离业务代码
- **[上下文感知的运行时状态](context-aware-runtime-state.md#context-aware-runtime-state)** — `ContextAware` 与 `ContextManagerInterface` 如何在 FPM、Swoole 与 worker 式运行时之间安全保存可变运行时状态
- **[身份作为框架契约](identity-as-a-framework-contract.md#identity-as-a-framework-contract)** — 为什么解析后的身份是共享的消费侧契约，而不是藏在某个包内的认证细节
- **[类型化输入绑定](typed-input-binding.md#typed-input-binding)** — 请求输入如何成为类型化、已校验的动作边界，而不是在控制器中重复手动解析
- **[跨运行时的一体应用模型](one-app-model-across-runtimes.md#one-app-model-across-runtimes)** — `Kernel`、共享服务与薄入口边界如何让 HTTP、CLI、计划任务与 worker 保持同一应用形态
- **[小而明确的包](small-explicit-packages.md#small-explicit-packages)** — Switon 为什么偏好清晰的包边界与组合，而不是一个隐藏的运行时大盒子

---

## 如何使用亮点 {#highlights}

将亮点用作桥接层：从首页或简短功能摘要开始，需要安装或精确契约时切换到所属包文档，需要再次理解高层权衡时回到这里。

---

## 另见 {#see-also}

- [入门](../getting-started/README.md#getting-started) — 安装、首次验证与默认路线
- [Switon 基础](../concepts/README.md#switon-fundamentals) — 框架模型与代码放置决策
- [HTTP 包](../http/README.md) — 请求、控制器与输入边界
- [DI 包](../di/README.md) — 依赖注入与命名服务
- [Context 包](../context/README.md) — 上下文对象与运行时作用域可变状态
