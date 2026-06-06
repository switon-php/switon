# 小而明确的包 {#small-explicit-packages}

Switon 偏好小的包边界、显式契约与可见的组合，而非一个试图一次拥有每个运行时关切的隐藏框架大盒子。

**为何是亮点：**
- 包边界保持可读，采纳从你实际需要解决的问题开始
- 包 `README.md`、`faq.md` 与 `design.md` 一次说明一个所属边界
- 契约保持足够窄，可在 HTTP、CLI、worker 与应用服务间复用
- 组合留在应用或包装配层，而非消失在一个单体默认中

## 框架选择 {#the-framework-choice}

该包形态遵循 [理解 Switon 框架方法论](../concepts/framework-methodology.md#framework-methodology) 中的同一方法论：先标准化分散的使用边界，但当框架不应隐藏时，将业务归属与局部组合留给应用。

因此 Switon 有许多专注的包文档，而非一本巨型运行时手册。一个包通常清晰拥有一个边界，例如 `HTTP`、`DI`、`Context`、`Event`、`DB`、`Query` 或 `ORM`，然后在另一个边界成为真正问题时再向外路由。

## 小包带来的好处 {#packages-buy}

主要好处是显式性。

- 包文档能说明包拥有什么、不拥有什么
- 应用代码可依赖小契约，而非宽泛的框架状态
- 你可组合包，而无需假装每个应用都需要同一完整栈

这也是文档选择。当一个包拥有一个边界时，其 `README.md` 可专注采纳，`faq.md` 可回答边界专属困惑，`design.md` 可解释权衡而无需重述整个框架。

## 契约优先于隐藏装配 {#contracts-hidden-assembly}

Switon 通常偏好可见的包契约，而非只有全部安装在一起才有意义的框架魔法。

实践中这意味着：

- 应用模型从 [Switon 基础](../concepts/README.md#switon-fundamentals) 更易追踪
- 服务放置从 [理解服务边界](../concepts/service-boundaries.md#service-boundaries) 更清晰
- 包选择通过 [包索引](../packages.md#packages) 保持显式

你先读边界，再组合需要的包。框架仍给出一种应用模型，但不会强迫一个巨大抽象层来隐藏 `HTTP` 止于何处、`DI` 始于何处，或 `Query` 何时应与 `ORM` 分离。

## 权衡 {#trade-offs}

该选择并非没有代价。

- 较大功能你需要阅读多个包文档
- 组合决策保持可见，因此应用必须选择边界，而非继承一个不透明的默认
- 与把更多行为藏在一个运行时盒子后的框架相比，有些任务感觉不那么「全包」

Switon 接受该代价，因为由此产生的应用结构更易推理、更易跨应用比较，且更不易把业务归属模糊进框架拥有的行为。

## 另见 {#see-also}

- [理解 Switon 框架方法论](../concepts/framework-methodology.md#framework-methodology) — Switon 为何先标准化使用边界
- [理解服务边界](../concepts/service-boundaries.md#service-boundaries) — 应用代码应在何处停止并交接
- [包索引](../packages.md#packages) — 按边界的包路由表
- [Switon 基础](../concepts/README.md#switon-fundamentals) — 逐包阅读前的完整应用模型
