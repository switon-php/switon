---
sidebar_label: HTTP Client 设计说明
description: 为何用单一契约、JSON 快捷路径与 RequestOptions 分层。
---

# 设计说明 {#design-rationale}

`switon/http-client` 保持公共面小：**一个客户端契约、一个请求对象、一个响应对象、类型化失败**。

## 参见 {#see-also}

- [HTTP Client 包](README.md#http-client-package) —— 快速开始、概念与配置
- [FAQ](faq.md#http-client-faq) —— 常见问题短答

---

## 核心选型 {#core-choice}

先标准化最常见的 HTTP 形态：

- JSON API 走快捷方法；
- 原始请求仍经 `request()`；
- `Response` 负责读取与解码辅助；
- 单次传输覆盖留在 `RequestOptions`。

## 取舍 {#trade-off}

不把每个 cURL 细节都摆在首页。

- **好处：** 常用路径易读；
- **好处：** 文档可扫读；
- **代价：** 高级传输调优需靠选项发现或源码契约。

## 刻意保持简单 {#deliberate-simplicity}

读者最常碰到的部分：

- `LocalFile`、`MemoryFile` 覆盖两种上传形态；
- `json()`、`text()`、`status()`、`header()` 覆盖常见读路径；
- 非 2xx 与解析失败保持**不同**异常类。
