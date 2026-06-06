---
sidebar_label: View 设计说明
description: 页面流程与模板执行分离；View 不成为第二套引擎。
---

# 设计说明 {#design-rationale}

`switon/view` 和 `switon/renderer` **分工明确**：前者管请求内 HTML 组装，后者管模板引擎执行。

## 参见 {#see-also}

- [View 包](README.md) — 用法、布局与 HTTP 桥接
- [FAQ](faq.md#view-faq) — 常见问题
- [Renderer 包](../renderer/README.md) — 模板引擎

---

## 要解决的问题 {#core-problem}

页面渲染很容易散在控制器和模板之间：

- 控制器手搓布局状态；
- 模板查找规则随模块漂移；
- widget 逻辑与片段渲染混在一起；
- 长驻运行时状态泄漏。

`switon/view` 先统一的是**页面**边界。

## 为何请求内状态 {#request-scoped-state}

`ViewContext` 把 layout、vars、content 绑在一次请求上，FPM 和 worker 都更容易推理；代价是多一层 context，但能避免跨请求泄漏。

## 为何有 Controller 映射 {#controller-mapping}

多数页面模板都属于单个 action；`Controller::Action` 贴近控制器文件，共享模板仍可用 `@view/...`。

## 为何单独 `ViewRenderer` {#viewrenderer-separate}

它把动作返回值转成 HTML，并通过 `VarsResolving` / `VarsResolved` 暴露扩展点；相关语义分布在 view / http / router 文档里，而不是塞在一章中。

## 为何 widget 与 block 分开 {#widgets-blocks-separate}

“带 PHP 的片段”和“纯模板片段”形状不同，两个 helper 比单一抽象更清晰。

## 刻意边界 {#deliberate-boundary}

- **View** — 页面流程、布局、HTTP 集成；
- **RendererInterface** — 模板执行；
- **AssetInterface** — 公共资源 URL。

不重复造第二套模板引擎，排错路径也更短。
