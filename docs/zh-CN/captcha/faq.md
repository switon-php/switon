---
sidebar_label: 验证码 FAQ
description: Captcha：接入、配置、异常、渲染与测试。
---

# Captcha FAQ {#captcha-faq}

面向搭建、配置与校验问题的短答。

> **前置：** [验证码包](README.md#captcha-package)

## 入门 {#getting-started}

### 表单里怎么接验证码？ {#add-captcha}

注入 `Switon\Captcha\CaptchaInterface`，对外提供 `create()`，在受保护动作上加 `#[Switon\Captcha\Attribute\CaptchaRequired]`。见 [快速开始](README.md#quick-start)。

### `create()` 返回什么？ {#return-value}

`['key' => '<uuid>', 'blob' => 'data:image/jpeg;base64,...']`。`blob` 可直接渲染；`key` 以 `captcha_key` 回传。

### 客户端必须提交哪些字段？ {#request-fields}

`captcha_key` 与 `captcha_code`。守卫从 POST 读这两项。若在方法签名里绑定 `Switon\Captcha\CaptchaProof`，缺字段会以 `CaptchaProofRequiredException`（HTTP 400）抛出，而不是 `CaptchaNotFoundException`。

## 配置 {#configuration}

### 验证码配在哪里？ {#where-to-configure}

在 `switon.yml` 里配置 `Switon\Captcha\CaptchaInterface`。仅当要换后端连接或前缀时才改 `Switon\Captcha\StoreInterface`。见 [配置](README.md#configuration)。

### 怎么改图幅或 TTL？  {#size-and-ttl}

按调用传参：`create($width = 120, $height = 40, $ttl = 300, ?string $tag = null)`。默认值也可在 `CaptchaInterface` 配置块里调。

### 怎么加载自定义字体？ {#fonts}

在 `Switon\Captcha\CaptchaInterface` 上设置 `fonts` 为 TTF 路径列表。每台 PHP 运行环境在运行时都要能读到这些文件。

### 能换存储实现吗？ {#different-store}

可以。实现 `Switon\Captcha\StoreInterface` 并改绑定。契约要求载荷能往返 `code`、`created_at`、`ttl` 以及可选的 `attempts`。

## 行为与错误 {#behavior-and-errors}

### 该捕获哪个异常？ {#which-exceptions}

用 `Switon\Captcha\Exception\InvalidCaptchaException` 一条分支处理所有常见的用户可见失败；框架仍会把子类映射成校验风格的载荷。仅在你绑定 `CaptchaProof`、需要区分「缺输入」和「码错」时，再单独捕获 `CaptchaProofRequiredException`。

### 为什么失败时还是 HTTP 200？ {#failure-http-200}

`InvalidCaptchaException` 默认返回 HTTP 200，形如 `{code: -1, msg: 'captcha.invalid', field: 'captcha'}`，便于按字段级校验展示。若 API 必须返回 4xx，可在自定义子类里覆盖 `getStatusCode()`。

### 输错也会扣 attempt 吗？ {#attempt-consumption}

会 —— **不匹配与匹配成功都扣一次**。`not_found`、`data_invalid`、`too_frequent`、`expired`、`attempts_exhausted` 在预检阶段短路，**先不会改变计数**。见 [尝试次数语义](README.md#attempt-semantics)。

### 实际会用哪个渲染后端？ {#which-backend}

加载了 Imagick 则优先用；否则用 GD。两个扩展都没有时，工厂在解析阶段会抛出运行时错误。

## 排错 {#troubleshooting}

### 校验一直失败 {#not-verifying}

看异常子类：

- `CaptchaNotFoundException`：存储未命中（key 错、TTL 过了、存储连不上）。
- `CaptchaTooFrequentException`：提交快于 `min_interval`。
- `CaptchaExpiredException`：提交前 TTL 已到期。
- `CaptchaAttemptsExhaustedException`：先前输错把 `attempts` 用尽。
- `CaptchaMismatchException`：条目在，但码不对。

### 图片不显示 {#image-not-loading}

确认 `blob` 原样进了 `<img src>`、当前环境加载了 `ext-imagick` 或 `ext-gd`，且配置的 `fonts` 路径可读。

### 怎样测试调用了 `verify()` 的代码？ {#testing}

绑定假的 `Switon\Captcha\StoreInterface`，对 `Switon\Core\RandomInterface` 打桩，并把 `min_interval` 设为 `0`，这样 `create()` 后可以立刻 `verify()`。
