---
sidebar_label: Storage FAQ
description: Storage：根目录、元数据、putFile 扩展名与流泄漏。
---

# Storage FAQ {#storage-faq}

**本地 blob 存储相关的短答。**

> **阅读前提：** [Storage 包](README.md)

## 入门 {#getting-started}

### 什么时候用本包？ {#when-to-use}

上传、导出、生成文件等**本地二进制**场景。

### 只支持一块本地盘？ {#one-local-disk}

围绕**本地文件系统路径**设计。

### 默认存哪？ {#default-root}

内置 `BlobStore` 使用 `@runtime/storage`。

### 能否改根目录？ {#change-root}

在 `Switon\Storage\BlobStoreInterface` 上配置 `root`。

## 行为 {#behavior}

### 如何判断存在？ {#exists}

`exists($key)`。

### 对象不存在时？ {#missing-object}

`get()` / `readStream()` 抛 `ObjectNotFoundException`；`size()` 不可得时返回 `null`。

### 删不存在的键会报错？ {#delete-missing}

**不会。** 内置实现静默返回。

### 能在这里存文件名、Content-Type？ {#metadata}

**不能。** 元数据放数据库或应用状态。

### 能生成上传/下载 URL？ {#urls}

**不能。** 由 HTTP 层处理。

### 支持 S3 等远程存储？ {#remote-storage}

**本包不支持。**

## 排错 {#troubleshooting}

### 为何 `MisuseException`？ {#bad-key}

键为空、以 `/` 开头、含 `..` 或规范化后仍非法。

### `putFile` 为何拒绝扩展名？ {#putfile-extension}

校验的是**存储键**上的扩展名，不是源文件路径上的后缀。

### 白名单字符串为空？ {#empty-allowlist}

解析 trim 后为空 → **不允许任何**扩展名。

### 键看起来合法仍写失败？ {#write-fails}

查 `root` 路径与文件系统权限。

### Worker 里句柄越来越多？ {#stream-leaks}

记得对 `readStream()` 返回的流调用 `fclose()`。

### `get` 还是 `readStream`？ {#get-vs-stream}

小文件用 `get()`，大文件用 `readStream()`。
