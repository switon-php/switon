---
sidebar_label: Composer Extra 设计说明
description: 单文件缓存、install/update 写入、尽力刷新与 path 覆盖。
---

# 设计说明 {#design-rationale}

`switon/composer-extra` 把 Composer 的 `extra` 元数据收成一份运行时缓存，让框架组件共用同一套发现面，而不是每次请求去翻 Composer 内部。

## 参见 {#see-also}

- [Composer Extra 包](README.md#composer-extra-package) —— 用法、缓存形态与读取 API
- [FAQ](faq.md#composer-extra-faq) —— 常见问题

---

## 核心问题 {#core-problem}

建在 Composer 上的框架常在运行时需要包发布的元数据：providers、commands、listeners、定时任务、队列任务等。没有共享层时，各组件会漂移：

- 某个包自己实现已安装包扫描；
- 另一个每次请求读 `composer.json`；
- 再一个把元数据缓存在别处、格式自造。

框架需要**一份缓存**和**一个读取器**，成本只在 install/update 付一次。

## 首要目标 {#primary-goal}

在 Composer 钩子里写一份 JSON 缓存，运行时只暴露一个读取器。

- **好处：** 启动简单、行为可预期。
- **好处：** 各组件读同一种结构、同一套排序规则。
- **代价：** 新鲜度跟着 Composer 跑，而不是实时扫包。

## 核心取舍 {#core-choice}

缓存在 install/update 时写入；运行时惰性读取并校验。

- 缓存文件位于 `vendor/switon/composer-extra.json`；
- `health()` 不报异常地报告缓存状态，调用方决定大声失败还是降级；
- `all()` 与 `collect()` 在缓存缺失时报错，因为需要发现的运行时代码不能悄悄继续；
- path 安装的包在本地开发时叠加实时 `composer.json`，monorepo 里改包不必再跑一遍 Composer。

## 取舍 {#trade-off}

缓存刷新是**尽力而为**：失败会报告，但不会中止 Composer。

- **好处：** 个别包元数据坏了，依赖操作仍 resilient。
- **代价：** 在再次成功跑通 Composer 之前，缓存可能缺失、陈旧或无效；消费方应显式调用 `health()` 识别这种状态。
