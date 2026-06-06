---
sidebar_label: Kernel FAQ
description: Kernel：parent::start、env 优先级、switon.yml 与 fatal 事件。
---

# Kernel FAQ {#kernel-faq}

**引导顺序、配置与启动失败相关的短答。**

> **阅读前提：** [Kernel 包](README.md)

## 入门 {#getting-started}

### 应继承哪个 Kernel？ {#which-kernel}

自定义运行时入口可继承 `Switon\Kernel\Kernel`；也可使用与传输方式匹配的专用 Kernel：`Switon\Http\Kernel`（HTTP）或 `Switon\Cli\Kernel`（CLI）。

### 为什么要 `parent::start()`？ {#parent-start}

基类拥有引导流程。`parent::start()` 在运行时启动前完成 env、配置、Provider 与 fatal 处理。

### 根目录检测失败？ {#root-detection}

向构造函数显式传入项目根。自动检测依赖 `vendor/autoload.php` **已经**加载。

## 环境 {#environment}

### 哪个 env 文件优先？ {#env-priority}

`.env.local` 先于 `.env` 加载；**系统环境变量**仍优先生效。

### 能否引用同文件后面定义的变量？ {#env-forward-reference}

不能。`$VAR` / `${VAR}` 必须已存在于系统 env，或在**同一文件更早**处定义。缺失引用抛出 `EnvVariableNotFoundException`。

## 配置 {#configuration}

### 主配置文件？ {#primary-config}

`switon.yml` 为基配置。需要仅 PHP 的值或预建实例时，`config/*.php` 作为叠在 `switon.yml` 上的 PHP 补丁。

### 如何换 YAML 文件？ {#custom-yaml}

将 `SWITON_CONFIG_FILE` 设为非空路径。`@root/...` 为相对项目根的便捷前缀。

### 重复服务 ID？ {#duplicate-service-id}

`switon.yml` 为基；`config/*.php` 在匹配数组键上叠加补丁。多个 PHP 文件对**同一顶层 ID** 保留**先出现者**。Kernel 专属的 `services` 在 Provider boot 前合并，对重复顶层 ID **Kernel 胜出**。

### 没有 `config/` 目录？ {#missing-config-dir}

不是错误；可仅用 YAML，或得到空配置映射。

## 错误 {#errors}

### 哪些 fatal 会变成事件？ {#fatal-error-types}

在已绑定事件分发器时，shutdown 阶段的 `E_ERROR`、`E_CORE_ERROR`、`E_COMPILE_ERROR`、`E_PARSE`、`E_RECOVERABLE_ERROR` 可变为 `Switon\Kernel\Event\FatalErrorOccurred`。

`Switon\Kernel\Event\ServiceBootstrapped` 在 Provider 注册并 boot 之后、且已绑定事件分发器时派发。

### `start()` 之前的 fatal？ {#fatal-before-start}

不算。Fatal 处理在引导进入 shutdown-handler 阶段**之后**才开始。

### 启动失败如何呈现？ {#startup-failures}

引导**快速失败**。若日志可用，Kernel 会记录；进程退出前原始失败也会写入 `console_log('error', ...)`。

### 何时启用协程模式？ {#coroutine-mode}

**仅当**运行时为 CLI、已加载 Swoole 扩展、且 Xdebug **未**启用。
