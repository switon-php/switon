---
sidebar_label: I18n 设计说明
description: 请求级 locale、扁平文件、回退链与缺键可观测性。
---

# 设计说明 {#design-rationale}

`switon/i18n` 把 **locale 选择、翻译查找、缺键可见性** 收成一条请求安全的边界。

## 参见 {#see-also}

- [I18n 包](README.md#i18n-package) — 用法、配置与常见坑
- [FAQ](faq.md#i18n-faq) — 问题与排错
- [HTTP 包](../http/README.md#http-package) — 请求侧 locale 输入

---

## 要解决的问题 {#core-problem}

语言选择往往最先「漂移」：一页读 Cookie、另一页读头，校验或后台任务再各自发明回退规则。本包让这些选择在请求作用域代码里**一致**。

## 首要目标 {#primary-goal}

在应用叠加额外策略之前，先标准化**一个 locale 源**与**一条翻译查找路径**。

- **好处：** 长驻运行时里请求级状态安全；
- **好处：** 各层回退可预测；
- **代价：** 持久化与用户偏好存储留在应用侧。

## 请求级 Locale {#request-local-locale}

`Switon\I18n\Locale` 把当前值放在请求上下文，而非 worker 共享状态，避免进程把语言带进下一请求。若偏好要跨请求保留，应用须单独存储。

## 扁平语言文件 {#flat-locale-files}

使用扁平 `array<string, string>`，不用嵌套翻译树。

- **好处：** 键稳定、回退简单、校验严格；
- **代价：** 文件里层次感较弱——用更清晰查找行为换这一点。

## 可配置回退 {#configurable-fallback}

单键经：当前 locale → 配置 fallbacks → 语言级 → 默认 locale。便于**部分翻译**上线，而不必建大型回退子系统。

## 缺键 {#missing-keys}

缺键多半是**内容缺口**，不是系统故障。`TranslationKeyMissing` 让缺失可监控，同时仍返回键本身，翻译 rollout 期间应用仍可用。

## 可选 ICU {#optional-icu}

默认占位符路径保持简单；仅当消息需要复数/select 等规则时才走 ICU，避免每条消息都背负 ICU 复杂度。
