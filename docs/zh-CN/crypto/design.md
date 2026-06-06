---
sidebar_label: Crypto 设计说明
description: 单契约上的派生、校验、轮换，避免散落的 openssl 调用。
---

# 设计说明 {#design-rationale}

`switon/crypto` 把可逆加密做小、做确定性、并易于轮换，应用代码不必直接碰 `openssl_*`，迁移窗口也不必改调用点。

## 参见 {#see-also}

- [Crypto 包](README.md#crypto-package) —— 用法、配置与对外契约
- [FAQ](faq.md#crypto-faq) —— 常见问题

---

## 核心问题 {#core-problem}

可逆加密很容易在实现上漂移：

- 有的服务把调用方字符串当密钥，有的按业务上下文派生；
- 有的部署轮换后仍要读旧密文；
- 校验规则与应保护的数据形状脱节。

框架需要一条边界，把这些收进配置，而不是散落在调用点。

## 首要目标 {#primary-goal}

先统一 `encrypt()` / `decrypt()`，再叠加密钥派生、校验与轮换。

- **好处：** 应用只走一条可逆边界。
- **好处：** 派生密钥可重复，存储、查找与 JWT 等可跨请求一致。
- **好处：** 结构化明文可在接纳前检查。

## 核心取舍 {#core-choice}

每次调用自包含；配置决定输入如何解释。

- **`mode`** 决定每次 `$key` 的含义（`auto`、`derived`、`raw`）。
- **`ValidatorInterface`** 插在 `decrypt()` 与接受之间，可配置，便于接域规则而无需子类化。
- **`RotatingCipher`** 组合两个 cipher，而不是引入 handler 树；模型是「新优先、legacy 回退」。
- **`getDerivedKey()`** 暴露在契约上，因为消费者（JWT、会话签名等）需要从同一 `secret` 得到稳定材料。

## 取舍 {#trade-off}

偏重可预期性，而非通用路由。

- **好处：** 失败更好推理；单 cipher、单规则、可选单次回退。
- **代价：** 不建模通用 KMS 或公钥流程，按目的地的 handler 树也不在范围内。

## 刻意保持简单 {#deliberate-simplicity}

几处边界有意收窄。

- 派生输出为小写 hex，便于在配置与日志里当纯文本传。
- 内置规则保持短小；其它走可调用或自定义校验器。
- `RotatingCipher` 的 `decrypt()` 要求非空规则，回退不会静默发生。
- Legacy 回退有意不重复套用同一规则，旧格式可能早于今天形状。
- 密钥生成放在 `Switon\Crypto\Command\KeyCommand`，与运行时加密代码分离。

这样可逆加密、迁移与运维工具不会糊成同一团。
