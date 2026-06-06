---
sidebar_label: HTTP Client FAQ
description: HTTP Client：request/head、RequestOptions、上传与类型化异常。
---

# HTTP Client FAQ {#http-client-faq}

**接线、请求与排错相关的短答。**

> **前置：** [HTTP Client 包](README.md#http-client-package)

## 入门 {#getting-started}

### 怎么发一次请求？ {#one-request}

注入 `Switon\HttpClient\HttpClientInterface`，调 `get()` 或 `post()`，用 `json()` 读响应。

### 什么时候用 `request()`？ {#when-request}

端点不是 JSON，或需要原始 method 路径时用 `request()`；**非 2xx 仍会抛异常**。

### 怎么调 `head()`？ {#head-request}

与 `request()` 走同一条传输路径，但**不**做 JSON 解析。

## 请求 {#requests}

### 怎么改超时或 TLS？ {#change-timeout}

用 `RequestOptions` 的 `timeout`、`connect_timeout`、`proxy`、`cafile`、`verify_peer`。  
`RequestOptions::of(3)` 只设 `timeout`；`connect_timeout` 除非你显式设置，否则保持默认。

### 怎么发文件？ {#send-file}

磁盘用 `LocalFile`，内存字节用 `MemoryFile`。

### 怎么看响应头？ {#inspect-headers}

单值用 `header()`；需要重定向后最终头块用 `rawHeaders()`。

### URL 能带查询参数吗？ {#url-query}

可以。传 `['https://api.example.com/users', 'page' => 2]`，客户端会拼查询串。

## 排错 {#troubleshooting}

### 为什么是类型化异常？ {#typed-exception}

传输失败、非 2xx、JSON 解析失败会分成不同异常类，方便决定重试、修响应还是修请求。

单次请求周围的事件：`HttpClientStart`、`HttpClientRequesting`、`HttpClientRequested`、`HttpClientSuccess`、`HttpClientError`、`HttpClientComplete`。

### 怎么看当前可配置项？ {#discover-options}

`bash bin/console config:options 'Switon\HttpClient\HttpClientInterface'`
