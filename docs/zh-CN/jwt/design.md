---
sidebar_label: JWT 设计说明
description: 应用自有 HMAC、单一 decode 边界；非公钥联邦平台。
---

# 设计说明 {#design-rationale}

`switon/jwt` 为应用自有 HMAC 令牌维持**严格单一边界**，不扩展成通用 JWT 平台。

## 参见 {#see-also}

- [JWT 包](README.md) — 用法、配置与密钥
- [FAQ](faq.md#jwt-faq) — 短答与排错

---

## 要解决的问题 {#core-problem}

若一条路径只校验 token 的一部分、另一条路径过早把 claims 当可信输入，令牌处理会很快混乱。

## 首要目标 {#primary-goal}

为应用签发的 token 保留**一条**已校验 claims 边界：

- **好处：** 调用方得到可信 claims 与分类异常；
- **好处：** 仅 `decode()` 让 token 数据成为可信输入；
- **代价：** 公钥联邦与更广 JWT 能力在包外。

## 核心选型 {#core-choices}

- **仅 HMAC：** `HS256`/`HS384`/`HS512` 覆盖常见自有 token；
- **单一边界：** `decode()` 在返回前校验结构、头、签名与时间；
- **令牌族路由：** key 在 token 边界显式选择；
- **类型化失败：** 畸形、签名、过期、未生效、算法、kid 不匹配分开。

## 取舍 {#trade-offs}

- API 小，但**没有**「只验签」的局部路径；
- key 路由显式，调用方须有意选择令牌族；
- claims 易消费，但 refresh 与更大认证体系在别处。
