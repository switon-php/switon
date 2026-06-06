---
sidebar_label: Crypto FAQ
description: Crypto：模式、secret、RotatingCipher、校验与排错。
---

# Crypto FAQ {#crypto-faq}

面向搭建、校验与轮换问题的短答。

> **前置：** [Crypto 包](README.md#crypto-package)

## 入门 {#getting-started}

### 怎么开始用 `switon/crypto`？ {#start-using}

注入 `Switon\Crypto\CipherInterface`，调用 `encrypt()` 与 `decrypt()`。先在 `derived` 模式下配置好 `secret`。见 [快速开始](README.md#quick-start)。

### 新项目该用 `derived`、`raw` 还是 `auto`？ {#pick-mode}

新数据用 `derived`。仅当每次调用的 `$key` 真是小写 hex 字节时用 `raw`。`auto` 用来衔接旧密钥形态，输入不是小写 hex 时会走派生。

### 怎么生成新密钥？ {#generate-key}

执行 `bash bin/console key:generate`。`--length=<n>` 改字符数，`--lowercase` 输出小写。

## 配置 {#configuration}

### 为什么会 `MissingSecretException`？ {#missing-secret}

`derived` 模式（以及 `auto` 在输入不是 raw hex 时）需要非空 `secret`。在 `switon.yml` 里设置，或仅在确有 hex 密钥字节时使用 `raw`。

### 怎么查看当前选项？ {#inspect-options}

`bash bin/console config:options 'Switon\Crypto\CipherInterface'`。

### 先调哪些选项？ {#important-options}

先动 `secret` 与 `mode`。只有要对齐已有存储或特定 OpenSSL 配置时才碰 `method`、`algo`。

### 怎么接 `RotatingCipher`？ {#wire-rotating-cipher}

把 `Switon\Crypto\RotatingCipher` 绑成 `CipherInterface` 的实现，并提供 `newCipher`（当前）与 `legacyCipher`（旧）子绑定。见 [密钥轮换](README.md#key-rotation)。

## 校验 {#validation}

### 内置规则有哪些？ {#built-in-rules}

`ascii`、`utf8`、`json`、`numeric`、`base64`、`uuid`、`email`。任意内置规则前加 `?` 可允许空串。

### 能自定义规则吗？ {#custom-rule}

可以。传入可调用 `fn(string $value): bool`，或换绑 `Switon\Crypto\ValidatorInterface` 做应用级规则。

### 为什么 `RotatingCipher::decrypt()` 必须有规则？ {#rotation-rule}

回退路径需要先判断新 cipher 路径的结果，再决定是否用 legacy。若要完全跳过校验，直接用 `Switon\Crypto\Cipher`。

### 校验失败为什么还会走 legacy？ {#validation-fallback}

`RotatingCipher` 把新 cipher 路径上的任意 `Switon\Crypto\Exception`（含 `ValidationFailedException`）都视为可尝试轮换的失败；旧密文按今天的规则校验失败是合理情况。

## 排错 {#troubleshooting}

### 解密报短输入？ {#short-input}

载荷比 IV 还短篇（AEAD 时还要含 tag）。确认传入的是完整二进制载荷；Base64 往返常会丢字节。

### `key:generate` 输出的是什么？ {#key-generate-output}

来自 `RandomInterface::chars()` 的随机**字符串**，不是 OpenSSL 裸字节。要小写可加 `--lowercase`。

### legacy 载荷仍失败？ {#legacy-failure}

可能新旧 cipher 都解不开，或新路径抛了非 crypto 异常从而绕过回退。先看异常类。

### 怎么测试加密代码？ {#testing}

测试里也面向 `CipherInterface` 契约；夹具要小，覆盖往返与失败分支。
