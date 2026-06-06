---
sidebar_label: Inspector FAQ
description: Inspector：无 Redis、inspector:watch、收集器与敏感数据。
---

# Inspector FAQ {#inspector-faq}

面向搭建、配置与常见 Inspector 问题的短答。

> **前置：** [Inspector 包](README.md#inspector-package)

## 入门 {#getting-started}

### 怎么确认 Inspector 已工作？ {#verify-working}

在 `dev`/`test` 发请求，打开 `X-Inspector-Link`。见 [快速开始](README.md#quick-start)。

### 没有 Redis 能用吗？ {#without-redis}

可以。`StorageInterface.ttl` 设为 **`0`** 切文件系统；载荷在 `@runtime/inspector/{key}.zip`（gzip JSON）。见 [存储设置](README.md#storage-settings)。

### 不打开 HTML 怎么读数据？ {#raw-data}

同一 key 换后缀 `.json`、`.txt`、`.raw`。见 [存储](README.md#storage)、[输出](README.md#output)。

### 终端里怎么盯新链接？ {#watch-terminal}

`bash bin/console inspector:watch`（仅 **Redis 模式**，依赖 pub/sub）。可按 handler 前缀与 IP 后缀过滤。见 [事件](README.md#events)。

## 配置 {#configuration}

### 如何在 dev/test 外强制启用？ {#force-enable}

`InspectorInterface.enabled: true`。见 [运行时开关](README.md#runtime-switches)。

### 怎么把 Inspector URL 写进响应体？ {#tail}

开启 `Switon\Inspector\InspectorInterface.tail`；响应内容为数组时，Inspector 在响应序列化阶段加入 `inspector` 字段。见 [运行时开关](README.md#runtime-switches)。

### 怎么改保留时间？ {#retention}

调整 `Switon\Inspector\StorageInterface.ttl`。见 [存储设置](README.md#storage-settings)。

Redis 存 `base64(gzip(json))`；文件系统载荷仅为 gzip。

### 怎么禁用某个收集器？ {#disable-collector}

在 `Switon\Inspector\CollectorDiscoveryInterface.collectors` 下将该 key 设为 `null`。见 [收集器设置](README.md#collector-settings)。

### 怎么自定义 UI？ {#template-customization}

覆盖 `Switon\Inspector\HtmlRendererInterface.pageTemplate`（页面壳）或 `tabs`（各收集器的 Tab 脚本）。见 [渲染设置](README.md#rendering-settings)。

### 怎么看当前可配置项？ {#discover-options}

执行 `bash bin/console config:options 'Switon\Inspector\InspectorInterface'`。若要看存储或收集器选项，请直接查询对应接口。

### 为什么 test 里没有 `X-Inspector-Route`？ {#route-header-test}

独立监听器；默认仅在 **`dev`** 自动启用，不在 `test`。

## 收集器与输出 {#collectors-and-output}

### 默认有哪些面板？ {#default-data}

常见：运行时、依赖、事件、日志、文件、应用元数据、Provider 发现等。见 [收集器组合](README.md#collector-composition)。

### SQL / 路由 / 渲染 Tab 为什么没有？ {#missing-tabs}

仅当其它包或应用提供了对应收集器时才会出现。见 [收集器组合](README.md#collector-composition)。

### 能加自定义收集器吗？ {#custom-collector}

在 `Switon\Inspector\CollectorDiscoveryInterface.collectors` 注册。见 [收集器设置](README.md#collector-settings)。

### 自定义收集器为什么只有通用 JSON Tab？ {#generic-tab}

未为该 key 配置专用 Tab 脚本时，Inspector 使用通用回退。见 [渲染设置](README.md#rendering-settings)。

## 排错 {#troubleshooting}

### 没有 `X-Inspector-Link` {#missing-header}

确认当前环境是否启用 Inspector、响应是否仍支持响应头，以及请求是否被 ApacheBench 等过滤掉。见 [快速开始](README.md#quick-start) 与 [运行时开关](README.md#runtime-switches)。

### Inspector URL 返回 404 {#link-404}

载荷可能已过期、后端已切换，或本机运行时文件已不存在。见 [存储设置](README.md#storage-settings)。

### 请求变慢 {#slowdown}

减少活跃收集器、缩短保留；日常调试可保持 Redis 模式。见 [存储设置](README.md#storage-settings) 与 [收集器组合](README.md#collector-composition)。

### 载荷里出现敏感数据 {#sensitive-data}

在收集器中过滤敏感数据，并控制 Inspector 暴露范围。
