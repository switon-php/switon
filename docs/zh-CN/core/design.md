---
sidebar_label: Core 设计说明
description: 为何 Core 只做共享词汇与契约，启动顺序归 Kernel。
---

# 设计说明 {#design-rationale}

`switon/core` 是很薄的一层，用来稳住 Switon 的共享词汇。它命名的是其它包去实现的角色，而不是把这些行为都收进 Core。

## 参见 {#see-also}

- [Core 包](README.md#core-package) —— 契约、约定与主阅读路径
- [FAQ](faq.md#core-faq) —— 边角与首次接入困惑
- [Kernel 包](../kernel/README.md) —— 拥有可执行启动顺序
- [DI 包](../di/README.md) —— 拥有容器行为与惰性代理

---

## 核心问题 {#core-problem}

各框架包需要对容器、应用元数据、scene、路径、context、错误使用同一套说法。没有这一共享层，名称就会漂移：

- 某个包自己发明「应用对象」，把元数据、容器访问与 scene 混在一起；
- 另一个包没有统一的 context 契约，只能去摸静态单例；
- 再一个包用略有不同的默认值重写路径别名或 JSON 辅助。

Core 的任务是稳住这套**词汇**，而不是在其上堆功能。

## 主要目标 {#primary-goal}

发布**契约与小工具**；具体运行时由兄弟包提供。

- **好处：** 测试可以桩掉 Core 契约而不必拉起整个框架。
- **好处：** 专门包可以替换默认实现而不破坏调用点。
- **代价：** 单靠 `switon/core` 跑不起应用；它依赖 `switon/di`、`switon/kernel`、`switon/context` 等。

## 核心取舍 {#core-choice}

沿着清晰边界拆分职责。

- **App 与 scene：** `AppInterface` 拥有**部署侧**元数据；`SceneManagerInterface` 拥有当前**运行时** scene。混在一起会得到有状态、含义随调用方而变的「app」对象。
- **共享服务与 context：** 服务在进程内共享；可变的请求级或连接级状态通过 `ContextAware` 与 `switon/context` 提供的 `ContextManagerInterface`。
- **属性优于配置 DSL：** `#[Autowired]`、`#[Scene]`、`#[ResourceAlias]`、`#[Communal]` 把框架元数据贴在它所描述的代码旁，而不是并排维护一套外部 DSL。
- **引导属于 Kernel：** `ServiceProviderInterface` 与 `ContainerInterface` 在 Core 里命名角色，但环境加载、provider 发现、scene 应用与启动顺序由 `switon/kernel` 拥有。

## 权衡 {#trade-off}

Core 停在**词汇与小工具**；不拥有完整运行时行为。

- **好处：** 包保持小而稳定，易于跨版本推理。
- **代价：** 文档必须说明**哪个包拥有哪项运行时决策**（自动装配解析在 DI，启动顺序在 kernel，状态传播在 context）。

## 刻意的简单 {#deliberate-simplicity}

**无强制力**的标记是有意的。

- `#[Communal]` 与 `ContextConnScoped` 表达作者意图；默认 `ContextManager` **不会**为它们分支。
- `Lazy` 是接口形标记，不是包装类；DI 负责把联合类型变成代理。
- `Json`、`Random`、`Filesystem` 等辅助类在默认策略上带观点，但表面积小，包可复用而不必子类化。

这让 Core 作为共享词汇仍然有用，却不必变成另一个运行时。
