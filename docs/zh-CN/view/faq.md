---
sidebar_label: View FAQ
description: View：模板解析、layout、widget vs block、ViewRenderer。
---

# View FAQ {#view-faq}

**页面渲染相关的短答。**

> **阅读前提：** [View 包](README.md)

## 模板 {#templates}

### 如何指定模板？ {#template-addressing}

显式 `@view/...` 或 `Controller::Action`。

### `Controller::Action` 如何解析？ {#controller-action-resolution}

从控制器文件路径推导，去掉 `Controller`/`Action` 后缀，并优先嵌套模板目录。

### 为何 `index` 可用扁平文件而 `edit` 仍找嵌套？ {#index-flat-fallback}

**仅**当没有嵌套目录时，`index` 才回退到扁平文件。

## 布局与片段 {#layouts-and-fragments}

### 如何启用/禁用布局？ {#layout-control}

`setLayout()` / `disableLayout()`（只要片段 HTML 时用后者）。

### `widget()` 与 `block()`？ {#widget-vs-block}

前者可含 PHP 逻辑；后者只含模板。

### 短 widget 名？ {#short-widget-names}

如 `Menu` → `App\Widget\MenuWidget`。

### 资源 URL？ {#asset-helper}

`AssetInterface::get()` / `asset()`，可对已有文件追加短版本哈希。

## HTTP 集成 {#http-integration}

### `ViewRenderer` 做什么？ {#view-renderer}

监听 `RequestRendering`，把动作结果映射为 vars，渲染处理器模板并写入 HTML。

### 最小 HTTP 渲染路径？ {#minimal-http-path}

在动作上使用 `ViewMapping` 并返回数组；如果响应已有内容，则**不**覆盖。

### 返回对象为何变成 `data`？ {#object-becomes-data}

只有数组或带 `toArray()` 的对象才会自动展开。

### `hasVar()` 对 `null`？ {#null-hasvar}

等同 `isset()`，为 false。

### 短 block 路径？ {#block-paths}

无 `@`/`/` → `@view/Blocks/{Name}`；含 `/` 则原样使用。

### Widget 模板路径？ {#widget-paths}

短名 → `App\Widget\{Name}Widget`，视图相对 widget 类文件位置解析。

## 排错 {#troubleshooting}

### 模板找不到 {#template-not-found}

核对 `@view/...` 或 `Controller::Action` 是否与文件布局一致。

### 布局未生效 {#layout-not-applied}

确认在 `render()` 前调用了 `setLayout()`，且同一请求内没有再执行 `disableLayout()`。

### URL 未重写 {#urls-not-rewritten}

需要启用 `rewrite`，并且 `render(..., $prefix)` 传入非空前缀。

### 如何测 View？ {#testing}

分别测模板解析、layout、变量合并、widget/block 输出与 `ViewRenderer` 映射。
