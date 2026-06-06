---
sidebar_label: Redis FAQ
description: Redis：getTransient、URI scheme、cluster 忽略 db 与 CLI 诊断。
---

# Redis FAQ {#redis-faq}

**池化、Transient、URI 与诊断相关的短答。**

> **阅读前提：** [Redis 包](README.md)

## 入门 {#getting-started}

### 何时用 `getTransient()`？ {#use-gettransient}

事务、扫描、阻塞命令等需要**固定连接状态**时。

### 池化客户端还能用普通命令吗？ {#pooled-commands}

可以。`get`、`set`、`del` 等是池化路径的设计用途。

### 为什么池化上 `multi()` / `scan()` 失败？ {#multi-scan-fail}

需要稳定连接；改 `getTransient()`。

## 连接 {#connection}

### 用哪种 URI？ {#which-uri-scheme}

- `redis://` — 单节点  
- `rediss://` — TLS  
- `sentinel://` — Sentinel（查询串 `master=` 可覆盖路径中的 master 名）  
- `cluster://` — Cluster  

### `heartbeat=0` 含义？ {#heartbeat-zero}

**不会**关闭检查；后续调用仍会检查已有连接。

### `cluster://` 支持 `db=` 吗？ {#cluster-db}

**不支持**，Cluster 忽略库选择。

## 诊断 {#quality-and-diagnostics}

### 自带哪些命令？ {#commands}

`redis:ping`、`redis:info`、`redis:server`。可传命名连接；空则用默认注入客户端。
