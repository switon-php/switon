---
sidebar_label: OpenAPI FAQ
description: OpenAPI：.yml 与 .yaml、sync 范围、deref 与 --strict。
---

# OpenAPI FAQ {#openapi-faq}

**跟进问题相关的短答。**

> **阅读前提：** [OpenAPI 包](README.md)

## 入门 {#getting-started}

### `open-api:init` 之后改哪里？ {#edit-after-init}

`openapi/openapi.yml`、`openapi/components/*.yml`、`openapi/controllers/*.yml`。

### 为什么 `operationId` 是 `user::index`？ {#operationid-user-index}

这是 Switon handler-id 形态，与路由文档、handler 命名和 lint 一致。

## 同步与导出 {#sync-and-export}

### 为什么 sync 写了 `controllers/user.yml`？ {#wrote-controller-file}

文件名来自 `operationId` 中 `::` **之前**的前缀，不是控制器类名。

### 为什么有 `components/entity.user.yml`？ {#created-entity-file}

`user::...` 类 id 预期的共享实体 schema 文件。

### 为什么 `.yaml` 被忽略？ {#why-yaml-ignored}

加载器**只读 `.yml`**。

### 为什么某路由没被 sync？ {#why-sync-skipped-route}

仅扫描类级 `#[RequestMapping]` 的 action；跳过 `#[ViewMapping]` 与 `*` 动词。

### `--deref` 解析什么？ {#deref-resolve}

仅合并文档内的本地 `#/...` 引用。

### export 为何保留/去掉 `components`？ {#export-components}

`--deref` 默认去掉顶层 `components`；需要时加 `--keep-components`。

### 下游工具应读哪个文件？ {#downstream-tools}

通常为 `openapi/dist/openapi.json`（或通过 `--out` 指定的路径）。

## Lint {#lint}

### 什么算错误？ {#fails-lint-error}

重复的 `operationId` 与合并文档内断裂的本地 `$ref` 指针。

### 为什么响应被警告？ {#lint-response-warning}

警告覆盖可读但偏弱的结构（缺响应、缺 `operationId`、缺路径参数、`entity.*` 提示等）。CI 要严格时用 `--strict`。

### 何时用 `--strict`？ {#use-strict}

希望警告也导致 CI 失败时。
