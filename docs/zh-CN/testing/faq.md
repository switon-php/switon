---
sidebar_label: Testing FAQ
description: Testing：setUpContainer、run.sh、事件与 Provider 自动注册。
---

# Testing FAQ {#testing-faq}

**包级测试搭建相关的短答。**

> **阅读前提：** [Testing 包](README.md)

## 搭建 {#setup}

### 需要包内 `tests/TestCase.php`？ {#local-testcase}

仅当包有共享 helper/fixture 时；保持薄封装并继承 `Switon\Testing\TestCase`。

### 依赖替换写在哪？ {#replacement-location}

`setUpContainer()` — 在自动注入与被测对象创建**之前**执行。

### 能直接跑 `vendor/bin/phpunit`？ {#direct-phpunit}

日常包测试请用 `bash tests/run.sh ...`，快捷参数与 monorepo/独立布局检测一致。

## 事件 {#events}

### 如何只断言派发、不跑监听器？ {#events-without-listeners}

`disableEventDispatching()`；`EventDispatcher` 仍会记录事件。

### 何时用 `MockEventDispatcher`？ {#mock-event-dispatcher}

需要绑定层本身就是「只收集、不转发」时。

## 默认行为 {#defaults}

### 测试容器预绑了哪些？ {#container-defaults}

Logger、Console、Random、事件分发器、Context、App、文件系统、时钟、类扫描、Provider 发现等常见契约。

### 如何关闭 Provider 自动注册？ {#disable-auto-register}

创建容器前设 `SWITON_TESTS_DISABLE_PROVIDER_AUTO_REGISTER=1`。

### 协程相关测试为何表现不同？ {#coroutine-default}

共享引导**默认关闭**协程运行时。

### 为何出现临时 `@view` / `@runtime`？ {#temp-path-alias}

未预绑定真实 `PathAlias` 时，容器指向临时目录。

### `TranslatorInterface` 为何返回 key？ {#stub-translator}

默认是 `StubTranslator`。

### 覆盖率报告在哪？ {#coverage-report}

`bash tests/run.sh --coverage` → `tests/coverage`（HTML）。
