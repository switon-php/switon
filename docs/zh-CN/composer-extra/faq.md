---
sidebar_label: Composer Extra FAQ
description: Composer Extra：运行时读 extra、allow-plugins、缓存与健康检查。
---

# Composer Extra FAQ {#composer-extra-faq}

围绕缓存、运行时读取器与 Composer 插件设置的短答。

> **前置：** [Composer Extra 包](README.md#composer-extra-package)

## 入门 {#getting-started}

### 运行时怎么读包的 `extra`？ {#use-extra}

注入 `Switon\ComposerExtra\ComposerExtraInterface`，调用 `get()`、`all()`、`has()`、`collect()` 或 `health()`。见 [快速开始](README.md#quick-start)。

### 为什么需要 `allow-plugins`？ {#allow-plugins}

Composer 2.2+ 默认拦截插件，直到根项目允许。在 `config.allow-plugins` 下加入 `"switon/composer-extra": true`。见 [配置](README.md#configuration)。

### 包不存在时 `get()` 返回什么？ {#get-miss}

空数组 `[]`，不是 `null`。需要「是否存在」时用 `has($packageName)`，空 `extra` 视为不存在。

### path 安装的包怎么叠加？ {#path-overlay}

无需额外操作。path 与 symlink 安装在加载缓存时检测，并在各包的实时 `composer.json` 上合并，直到进程结束或缓存重建。

## 缓存与运行时 {#cache-and-runtime}

### 缓存何时重建？ {#when-regenerated}

在 `composer install`、`composer update` 之后，通过 `post-install-cmd` 与 `post-update-cmd` 钩子。

### 怎么快速看缓存是否健康？ {#cache-health-check}

调用 `ComposerExtraInterface::health()`，看 `ok` 与 `reason`。非 `ok` 时包括：`cache_missing`、`cache_unreadable`、`invalid_json`、`invalid_shape`、`cache_empty`。

### 缓存文件缺失会怎样？ {#cache-missing}

`health()` 返回 `ok: false` 且 `reason: cache_missing`。`all()` 与 `collect()` 抛出 `Switon\ComposerExtra\Exception\ComposerExtraNotFoundException`，因为读路径依赖缓存。

### 能手改生成好的缓存吗？ {#manual-edit}

不能。下次 install 或 update 时插件会覆盖。
