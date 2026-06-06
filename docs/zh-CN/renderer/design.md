---
sidebar_label: Renderer 设计说明
description: Frames 显式承载 section/stack/theme；引擎按扩展名分离。
---

# 设计说明 {#design-rationale}

`switon/renderer` 让模板状态**显式**可见，而不是藏在长生命周期的渲染服务里。

## 参见 {#see-also}

- [Renderer 包](README.md) — 渲染事务、引擎与模板组合
- [Renderer FAQ](faq.md#renderer-faq) — 关于 `Frames`、主题与 Sword 的短答

---

## 为何存在 {#purpose}

模板渲染往往不止最终 HTML。

- sections 与 stacks 需要共享状态；
- partial 需要访问当前事务；
- 主题查找需要一致规则；
- 编译模板需要清晰的运行时边界。

本包通过 **`Frames`** 使上述状态可见。

## Frames 优先 {#frames-comes}

`Frames` 既是渲染结果，也是共享事务对象。

由此约定：

- 输入变量；
- 内容；
- sections；
- stacks；
- once 标记；
- theme。

代价是渲染返回对象而不只是字符串；收益是组合状态明确可查。

## 引擎分离 {#engines-separate}

`Renderer` 按文件扩展名选择引擎。

这使 `.phtml` 与 `.sword` 处于同一条渲染路径，同时各模板格式仍可保持自身编写风格。

## 顶层捕获留在 Renderer {#level-capture-renderer}

Renderer 仍拥有顶层输出缓冲，并防止同一执行上下文中不安全的嵌套捕获。

该边界使脆弱捕获逻辑不进入模板，片段、sections 与 stacks 交给事务对象处理。

## 刻意不拥有 {#outside}

本包**不**拥有：

- 控制器路由；
- 请求生命周期约定；
- 对单一模板语言的强制选择。
