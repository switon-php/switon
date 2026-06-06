---
sidebar_label: Router 设计说明
description: 显式发现、前缀与匹配分离；诊断命令贴近包边界。
---

# 设计说明 {#design-rationale}

`switon/router` 让发现保持显式，让路径组合保持可预测，并把**前缀处理**和路由定义分开。

## 参见 {#see-also}

- [Router 包](README.md) — 用法、注册与核心规则
- [FAQ](faq.md#router-faq) — 常见问题

---

## 要解决的问题 {#core-problem}

如果发现、前缀和匹配规则分散开，路由表就很难解释。本包要标准化的是从控制器属性到最终匹配的追溯路径。

## 首要目标 {#primary-goal}

先统一**一张路由表**，再叠加发现、前缀与诊断：

- 显式发现 → 路由表更可预测；
- 前缀属于**匹配阶段**，不必写进每条存储路由；
- 字面量路由更快，动态路由只在需要时付正则成本。

## 取舍 {#trade-off}

Router 不把路由注册建模成对每个 public 类的隐式扫描。

- **好处：** 发现过程始终可见。
- **好处：** 路由行为更容易检查。
- **代价：** 控制器必须先有 `#[RequestMapping]` 才能路由。

## 刻意保持简单 {#deliberate-simplicity}

`router:list`、`router:resolve`、`router:prefix` 把路由诊断留在包边界内，而不是临时 debug 代码里。
