---
sidebar_label: Core FAQ
description: Core：直装、项目根、App/Scene/Context、路径别名、Communal、Lazy、引导边界。
---

# Core FAQ {#core-faq}

**常见问答。**先过一遍 [快速开始](README.md#quick-start)，需要查某一点再回到本页。

> **阅读前提：** [Core 包](README.md)

## 入门 {#getting-started}

### 需要直接装 `switon/core` 吗？ {#direct-install}

只有当你**直接使用** Core 契约或工具时才需要；多数技术栈会**传递依赖**带进来。见 [安装](README.md#installation)。

读完 [快速开始](README.md#quick-start) 后，其余按需翻 README 概念小节或本 FAQ。

### 怎么取项目根目录？ {#project-root}

用 `Runtime::getRoot()`：先看环境变量 `SWITON_ROOT`，否则回退到 Composer `autoload.php` 所在推断。测试或引导里可用 `Runtime::setRoot()` 覆盖。见 [路径与运行时](README.md#paths-and-runtime)。

## 契约 {#contracts}

### 何时用 `AppInterface`？ {#when-app-interface}

只用于应用元数据：id、name、version、环境、debug、时区。见 [应用元数据与 scene](README.md#app-metadata-vs-scene)。

### 为何单独有 `SceneManagerInterface`？ {#scene-split}

表示当前运行场景（如 `cli` / `http`），**不要**并进 `AppInterface`。见 [应用元数据与 scene](README.md#app-metadata-vs-scene)。

### 何时用 `ContextAware`？ {#when-context-aware}

可变请求或连接状态应落在 Context 对象里，而不是共享服务字段时。契约是 `ContextManagerInterface`，默认实现由 `switon/context` 提供。见 [Context 状态](README.md#context-state)。

### 何时用 `PathAliasInterface`？ {#when-path-alias}

希望用 `@root`、`@app`、`@runtime`、`@vendor`、`@resources` 或自定义别名，而不是裸路径时。见 [路径与运行时](README.md#paths-and-runtime)。

### `#[Communal]` 做什么？ {#what-communal}

**只是标记。** Core 运行时不强制「共享属性」行为；包与作者用它表达「有意共享」的约定。

### `Lazy` 是什么意思？ {#lazy}

在 `FooInterface|Lazy` 这类联合类型里延迟解析；首次访问时由 DI 包包成 `LazyPropertyProxy`。`#[Autowired]` 是属性注入入口，由 DI 消费；`instances: true` 表示数组元素按**服务实例**解析，而不是普通配置值。见 [自动装配](README.md#autowiring)。

## 引导 {#bootstrap}

### 日常代码能随便 `App::get()` 吗？ {#app-get}

尽量注入 `AppInterface` 或 `ContainerInterface`。`App::get()` / `App::make()` 留给引导边缘或兼容层。见 [自动装配](README.md#autowiring)。

### 为什么 `#[Autowired]` 一直是 `null`？ {#autowired-null}

对象多半是用 `new` 建的，没经容器或 invoker。需要注入时让框架创建实例。见 [自动装配](README.md#autowiring)。

### 该用 `raise()` 还是 `new` 异常？ {#raise}

需要 `{key}` 替换与上下文存储时用 `Exception::raise()`；处理普通 PHP 异常类型时才直接 `new`。见 [辅助类与异常](README.md#helpers-and-exceptions)。
