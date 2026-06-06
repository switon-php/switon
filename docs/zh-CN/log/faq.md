---
sidebar_label: 日志 FAQ
description: Switon 日志组件的安装、配置与排错问答。
---

# 日志 FAQ {#log-faq}

安装、配置与常见问题，短答为主。

> **先读：** [日志包](README.md)

## 入门 {#getting-started}

### 业务代码里怎么记日志？ {#add-logging}

注入 `Psr\Log\LoggerInterface`，使用常规 PSR-3 方法。示例见 [快速开始](README.md#quick-start)。

### 默认写到哪里？ {#default-destination}

默认装配会把通过过滤的条目写到 `@runtime/logger/{scene}.log`。容器化部署时，若希望接入平台日志采集，请用 `Switon\Logging\Appender\StdoutAppender` 走 stdout，而不是依赖宿主机上的文件路径。

### 全应用能共用一个 Logger 吗？ {#same-logger}

能。本包就是为 HTTP、CLI、Worker 与库代码共用一套 `Psr\Log\LoggerInterface` 而设计的。

## 配置 {#configuration}

### 怎么改日志级别？ {#change-level}

用绑定上的 `level` 作全局基准，再在 `levels` 里按分类加覆盖。见 [配置](README.md#configuration)。

### `levels` 覆盖怎么生效？ {#category-overrides}

`levels` 按**分类**做**前缀**匹配；**最长**匹配的前缀决定本条日志的**阈值**。比该阈值**更详细**的级别会被丢弃。详见 [配置](README.md#configuration)。

### 能否让某一分类只进某一个 Appender？ {#route-by-category}

不能。通过过滤的条目会对**每一个**已配置的 Appender **扇出**。只想减噪请调 `levels`；要按目的地分流需在应用侧或采集侧处理。

### 某一个 Appender 失败后，后面的还执行吗？ {#appender-failure}

Logger 按顺序调用 Appender 且**不吞异常**。内置文件 Appender 会隔离写盘失败；自定义 Appender 若抛错，会阻断后续 Appender，异常也可能冒泡到业务代码。总述见 [输出](README.md#output)。

### `LoggerLogged` 里能改什么？ {#logger-logged-boundaries}

在 Appender 运行前，只能往 `LogEntry::extra` **追加**字段。本条记录的 `level`、`category`、`message` 视为已定；**不要从监听器里再次调用 Logger**。

### 怎样查看当前版本有哪些配置项？ {#discover-options}

```bash
bash bin/console config:options 'Psr\Log\LoggerInterface'
```

若还要查某个 Appender 的专用选项，对该 Appender 类再执行一次 `config:options`。

## 行为 {#behavior}

### 我的代码会得到什么分类？ {#predict-category}

分类来自**调用点**的类与方法，例如 `App\Service\UserService::register()` → `app.service.user.register`。`message` 实现了 `Switon\Core\Categorizable` 时以接口为准；存在 `context['exception']` 时以**抛出位置**为准而非 `catch` 处。详见 [分类](README.md#category)。

### 消息和异常怎么写？ {#format-messages}

句子写在 `message`，句内值用 `{key}`，异常放在 `context['exception']`。得到 `LogEntry` 后：占位符消化进 `message`，其余键在 `extra`，异常正文进入 `message`。细则见 [消息与上下文](README.md#message)。

### 如何统一附加 request id 等字段？ {#add-correlation}

监听 `Switon\Logging\Event\LoggerLogged`，在写出前写入 `LogEntry::extra`。本条记录的 `level`、`category`、`message` 勿改；**不要在监听器里再调用** `Psr\Log\LoggerInterface`。保持监听器轻量，只做横切补充。

### 测试里能用 `MemoryAppender` 吗？ {#memory-appender}

能。`Switon\Logging\Appender\MemoryAppender` 是常用内存目的地；条目按请求或协程上下文隔离。

## 排错 {#troubleshooting}

### 为什么完全没有日志？ {#logs-missing}

先查写出目标与实际生效级别。常见原因是 Appender 目标不对，或某条前缀覆盖把阈值抬得过高。

### 为什么有的有、有的没有？ {#partial-logs}

多半是 `levels` 里更具体的前缀把部分级别过滤掉了。检查**最长匹配前缀**，包含 Logger 绑定自带的默认值，不单看你写在 `switon.yml` 里的片段。

### 文件 Appender 写不进去会怎样？ {#file-write-failure}

失败会通过**单独通道**报告。不要在**该失败处理路径**上再次用 `Psr\Log\LoggerInterface` 打日志。

### 怎样测试会打日志的代码？ {#testing}

使用 `Switon\Logging\Appender\MemoryAppender`，或对 `Psr\Log\LoggerInterface` 使用 stub/mock。
