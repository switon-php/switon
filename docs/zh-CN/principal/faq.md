---
sidebar_label: Principal FAQ
description: Principal：不负责认证/租户发现；guest 与 tenantless 异常。
---

# Principal FAQ {#principal-faq}

**身份、租户状态与边界归属相关的短答。**

> **阅读前提：** [Principal 包](README.md)

## 入门 {#getting-started}

### 本包负责认证吗？ {#authenticate-users}

**不负责。** 只承载其它边界解析后的当前身份状态。

### 本包从 Host/Header 发现租户吗？ {#discover-tenants}

**不负责。** 只承载已解析的当前租户状态。

### 何时注入 `IdentityInterface`？ {#inject-identity}

需要当前调用方、访客状态或规范化角色时。

### 何时注入 `TenantInterface`？ {#inject-tenant}

需要当前租户 id 或 code 时。

## 状态 {#state}

### guest 是什么意思？ {#guest-meaning}

表示当前**未**存储已认证调用方。此状态下 `getId()` 与 `getName()` 抛出 `NotAuthenticatedException`；`getRoles()` 返回 `[]`。

### tenantless 是什么意思？ {#tenantless-meaning}

表示当前**未**存储租户。此状态下 `getId()` 与 `getCode()` 抛出 `TenantNotAvailableException`。

### `set()` / `clear()` 做什么？ {#set-clear}

`set()` 写入已解析状态；`clear()` 回到访客或无租户。`Identity::set()` 会 trim 并规范化角色为 `list<string>`；`Tenant::set()` 会 trim 租户 code。

### 身份与租户能同时不同吗？ {#independent-state}

可以。二者是**独立**的运行时契约。
