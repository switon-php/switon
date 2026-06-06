---
sidebar_label: JWT FAQ
description: JWT：decode 边界、kid、密钥格式与异常顺序。
---

# JWT FAQ {#jwt-faq}

**令牌边界、密钥与失败处理相关的短答。**

> **阅读前提：** [JWT 包](README.md)

## 入门 {#getting-started}

### 应注入哪个接口？ {#which-interface}

`Switon\Jwt\JwtInterface`。

### `decode()` 校验什么？ {#decode-validates}

令牌格式、头 `alg`、可选头 `typ`、可选头 `kid` 类型、payload 结构、`kid` 一致性、签名，以及数值型 `exp` / `nbf`。详见 [信任边界](README.md#trust-boundary)。

### 何时用 `kid`？ {#kid-usage}

不同租户、服务或发布版本需要**独立签名密钥**时。见 [令牌族路由](README.md#token-family-routing)。

### 为什么没有 `verify()`？ {#no-verify}

刻意保持**单一信任边界**：只有 `decode()` 让 claims 成为可信输入。

## 配置 {#configuration}

### 怎么配？ {#configure}

在 `switon.yml` 增加 `Switon\Jwt\JwtInterface` 配置块。常用项为 `alg`、`key`、`leeway`。

### 怎么看可配置项？ {#discover-options}

`bash bin/console config:options 'Switon\Jwt\JwtInterface'`

### 支持哪些算法？ {#supported-algorithms}

`HS256`、`HS384`、`HS512`。

### `leeway` 做什么？ {#leeway}

为 `exp`/`nbf` 增加时钟偏差容差。

## 密钥 {#keys}

### `kid` 为 null 时？ {#kid-null}

JWT 使用配置的 `key`；`key` 为 null 时，经 `Switon\Crypto\CipherInterface` 从 `jwt` 派生默认密钥。

### `key` 格式？ {#key-format}

小写十六进制、偶数长度；不必与算法强度严格匹配。

### 应用代码应直接读签名密钥吗？ {#expose-key}

通常不要。应用代码保持 `encode()` / `decode()`，除非明确需要 `Switon\Crypto\CipherInterface` 的底层密钥材料。

## 排错 {#troubleshooting}

### 应先捕获哪些异常？ {#catch-order}

按边界重要性：常见顺序 `ExpiredException` → `KidMismatchException` → `SignatureException` → `MalformedException` → `NotBeforeException`；空 token 加 `NoCredentialException`。

### 部署后 `SignatureException`？ {#signature-after-deploy}

查有效 key 边界：配置 `key`、期望 `kid`、crypto 主密钥、token 是否来自不同签发边界。

### `AlgorithmMismatchException`？ {#alg-mismatch}

token 头 `alg` 与配置算法不一致。

### HTTP 状态码？ {#http-status}

JWT 相关异常映射为 **401**。

### JWT 与授权如何配合？ {#jwt-and-authz}

先用 JWT 校验 token，再用 [授权包](../authz/README.md) 落实权限。
