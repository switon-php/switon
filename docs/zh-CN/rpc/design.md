---
sidebar_label: RPC 设计说明
description: 队列上一请求一回复；不扩展为第二套传输平台。
---

# 设计说明 {#design-rationale}

`switon/rpc` 把基于队列的请求-回复做得**薄、显式、与 executor 对齐**。

## 参见 {#see-also}

- [RPC 包](README.md) — 用法、配置与 `rpc:daemon`
- [FAQ](faq.md#rpc-faq) — 接线与常见问题
- [Queue 包](../queue/README.md) — 传输与信封
- [Executor 包](../executor/README.md) — 提交与并发

---

## 要解决的问题 {#core-problem}

基于队列的 RPC 很容易长成「第二套传输平台」。框架只需要**一个**队列原生的请求-回复边界。

## 首要目标 {#primary-goal}

标准化 **`call()` / 一条回复 / 一次超时**：

- 请求传输留在 `switon/queue`；
- 处理提交留在 executor；
- 协议错误（畸形回复）保持可见。

## 取舍 {#trade-off}

- **好处：** 调用方获得小而同步的契约。
- **好处：** Worker 行为与队列、executor 保持一致。
- **代价：** 流式、多回复与公开跨语言 RPC 不在范围内。

## 刻意限制 {#deliberate-limits}

- 单进程复用一条回复队列；
- `methods` 仅做逻辑名 → handler 字符串映射；
- 常规定位 Worker 入口为 `rpc:daemon`；
- 事件停在协议边缘，不侵入业务层。
