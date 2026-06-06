---
sidebar_label: DI FAQ
description: DI：注入、get/make、Factory、?Type 与 Lazy、容器排查。
---

# DI FAQ {#di-faq}

**常见问题速答。** 先过 [快速开始](README.md#quick-start)，需要时再查本页。

> **阅读前提：** [DI 包](README.md)

## 入门 {#getting-started}

### 怎么注入依赖？ {#inject-dependency}

声明带类型的 `#[Autowired]` 属性，让容器创建对象。见 [快速开始](README.md#quick-start)。

### 什么时候能自动解析？ {#when-auto-resolution-works}

`XxxInterface` 与 `Xxx` 在同一命名空间时。见 [接口优先自动装配](README.md#interface-first-autowiring)。

### 标量或数组怎么注入？ {#inject-scalars-or-arrays}

用类配置或 `make()` 参数。数组合并与 `instances: true` 见 [Autowired](autowired.md)。

### `get()` 和 `make()` 怎么选？ {#choose-get-or-make}

共享能力用 `get()`，每次新建用 `make()`。见 [共享 vs 新建](README.md#shared-versus-fresh)。

## 命名实例 {#named-instances}

### 多个实例怎么注入其中一个？ {#multiple-instances}

代码里槽位固定 → `Factory`；名称只有运行时才知道 → `NamedLookupInterface`。见 [命名槽](README.md#named-slots)。

### `?Type` 能让对象注入可选吗？ {#optional-dependency-shape}

不能。`?Type` 只改 PHP 类型；要延迟解析用 `Type|Lazy`。

## 排错 {#troubleshooting}

### 为什么 `#[Autowired]` 还是 `null`？ {#autowired-null}

多半用 `new` 建的，没经容器或 invoker。见 [使用注意](README.md#keep-in-mind)。

### 怎么查看容器？ {#inspect-container}

- 单个服务：`bash bin/console container:inspect --id 'App\Service\ProductService'`
- 定义列表：`bash bin/console container:definitions --filter 'App\Service'`
- 已解析单例：`bash bin/console container:instances --filter 'App\Service'`

### 先记哪些异常？ {#important-exceptions}

优先熟悉 `NotFoundException`、`MissingConfigurationException`、`MissingTypeDeclarationException`、`InterfaceAutowiringException`、`ServiceAlreadyResolvedException`。更多上下文见 [README — 使用注意](README.md#keep-in-mind)。
