---
sidebar_label: 验证码设计说明
description: 无 Session 挑战、共享存储与 attempt 语义的设计取舍。
---

# 设计说明 {#design-rationale}

`switon/captcha` 把「签发挑战 — 校验证明」收成一道边界，同一套流程可用于 HTTP API、SPA 和传统表单，而不依赖粘性 PHP Session。

## 参见 {#see-also}

- [验证码包](README.md#captcha-package) —— 用法、配置与运维决策
- [FAQ](faq.md#captcha-faq) —— 常见问题与排错

---

## 核心问题 {#core-problem}

PHP 应用里的验证码实现容易失控：

- 答案放在 `$_SESSION`，SPA 与 JSON API 没有粘性 Session 就很难校验；
- 各控制器自建字段名、TTL、重试规则；
- Imagick 与 GD 的差异泄漏到调用点；
- 「不匹配、过期、缺输入」等错误语义彼此不一致。

框架需要单一边界：签发挑战并校验证明，避免每个控制器各写一套。

## 首要目标 {#primary-goal}

对外只暴露 `CaptchaInterface::create()` 与 `CaptchaInterface::verify(CaptchaProof)`，底下由共享存储支撑。

- **好处：** 只要共库，任意 Worker 都能校验任意节点签发的挑战。
- **好处：** SPA、传统表单与 JSON API 共用同一对字段（`captcha_key`、`captcha_code`）。
- **代价：** 部署必须提供共享存储；无 Session 的体验仍然依赖网络侧状态。

## 核心取舍 {#core-choice}

浏览器只带 `key`，答案不出服务端。

- `create()` 把 `code`、`created_at`、`ttl` 与剩余 `attempts` 写入 `StoreInterface`。
- `verify()` 读出条目，做频次、过期与次数检查，再**不区分大小写**比对 `code`。
- `CaptchaRequired` 与 `CaptchaProofResolver` 把 HTTP 输入映射成 `CaptchaProof`，调用方不用自己拼输入。

这样校验路径短，控制器也不必关心 Session 亲和、存储细节和渲染差异。

## 取舍 {#trade-off}

**不匹配与成功都会消耗一次 attempt**；只有 `not_found`、`too_frequent`、`expired`、`attempts_exhausted`（以及数据无效类预检）会短路。

- **好处：** 暴力猜测与猜中成本相同，「被拒后刷新」成为自然交互。
- **代价：** 网络抖动可能导致用户没看见成功响应却已被扣 attempt；`attempts` 默认较小会较早暴露这类问题。

## 刻意保持简单 {#deliberate-simplicity}

渲染在构造时选一次（Imagick 优先，GD 兜底）。默认契约不搞多种 handler、多图排版或音频验证码。

- 对外表面保持 `CaptchaInterface` 两个方法；
- 仅某一适配支持的旋钮（例如 Imagick 的 `distort`）允许存在，不做强行归一；
- 字段级错误语义走 `InvalidCaptchaException`，一条异常路径覆盖 UX。
