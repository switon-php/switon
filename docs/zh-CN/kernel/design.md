---
sidebar_label: Kernel 设计说明
description: 引导编排器而非长驻运行时；env→config→Provider 顺序。
---

# 设计说明 {#design-rationale}

`switon/kernel` 把应用启动收拢到**一处可预测**的流程。

## 参见 {#see-also}

- [Kernel 包](README.md) — 公开引导契约与首次接入
- [Kernel FAQ](faq.md#kernel-faq) — env、配置与启动错误短答

---

## 要解决的问题 {#core-problem}

应用引导容易散在多个入口：

- 某脚本读 env
- 另一处合并配置
- 第三处接线 Provider
- fatal 处理很晚才加，或被遗忘

本包将上述工作标准化，使应用经**同一条**已知引导路径进入运行时。

## 首要目标 {#primary-goal}

启动顺序显式化：

- `.env.local` 覆盖 `.env` 同键；
- 系统环境变量覆盖文件；
- env 在 config 插值之前；
- config 在 Provider 引导之前；
- Provider 在运行时接管之前注册；
- fatal 处理仅在引导到达边界之后。

这使应用代码有**稳定**的启动入口，同时仍将 HTTP、CLI 或 worker 主循环留在各运行时包中。

## 核心选型 {#core-choice}

选择**引导编排器**，而非长驻运行时宿主：

- Kernel 拥有启动协调；
- `switon.yml` 仍是基引导面；
- 需要时 `config/*.php` 作 PHP 补丁；
- Composer-extra 发现避免手写 Provider 列表。

足够小以便理解，又足够强以消除各应用引导漂移。

## 取舍 {#trade-off}

本包用**一条**清晰的启动契约，替代多种临时入口风格。

- **好处：** 启动行为更易预测、可文档化；
- **好处：** env、配置、Provider、fatal 保持一致；
- **代价：** 自定义启动顺序放在 Kernel 外。

## 刻意保持简单 {#deliberate-simplicity}

Kernel **不**拥有运行时循环。

- **不**取代 HTTP 或 CLI 入口；
- **不**隐藏应用特有的启动逻辑；
- **只**为下一层准备好应用。

边界清晰：Kernel 负责引导，组件包负责各自的运行时行为。
