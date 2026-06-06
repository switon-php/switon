---
sidebar_label: DI 包
description: switon/di：#[Autowired]、interface-first 自动装配、Factory 命名槽与 get/make。
---

# DI 包 {#di-package}

`switon/di` 为 Switon 应用提供**基于属性的依赖注入**：interface-first 自动装配、命名槽，以及清晰的**共享实例 vs 每次新建**划分。

**关键特性：**

- **PSR 友好：** 默认可用 `Psr\Container\ContainerInterface`；
- **接口优先：** 同命名空间下 `XxxInterface` → `Xxx` 自动解析；
- **属性注入：** `#[Autowired]` 支持服务、标量、数组与 `Lazy`；
- **共享与新建：** `get()` 取共享服务；`make()` 造新实例；
- **命名槽：** `Factory` 把一个契约映射到多个命名实例；
- **别名重定向：** `#[Autowired]` 可用 `#name` 或完整服务 ID 指向 `Type#name`；
- **运行时查找：** 名称来自数据时用 `NamedLookupInterface` 动态选实例。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+、`ext-json`

```bash
composer require switon/di
```

---

## 快速开始 {#quick-start}

- 声明带类型的 `#[Autowired]` 属性；
- 让容器创建对象。

下文示例里的 `App\...` 为示意，除非出现真实的 `Switon\...` 或 PSR 命名空间。

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;

interface CacheInterface {}
class Cache implements CacheInterface {}

class ProductService
{
    #[Autowired] protected CacheInterface $cache;
    #[Autowired] protected int $ttl = 3600;
}
```

```yaml
# switon.yml
App\Service\ProductService:
  ttl: 7200
```

- 容器创建 `ProductService`；
- 注入 `CacheInterface`；
- 应用 `switon.yml` 里的标量覆盖。

---

## 核心概念 {#core-concepts}

### 接口优先自动装配 {#interface-first-autowiring}

`XxxInterface` 与 `Xxx` 在同一命名空间时，通常**无需**额外绑定。

实现类在别的命名空间、类名不一致，或一个契约对应多种实现时，再在配置里显式声明。

### `#[Autowired]` 可注入什么 {#autowired-values}

常见四类：

- 服务对象；
- 标量；
- 数组；
- `Switon\Core\Lazy`（延迟解析）。

注入发生在构造函数逻辑**之前**；`new` 手建对象**不会**注入。

### 共享 vs 新建 {#shared-versus-fresh}

- `get()` — 共享（单例式）服务，适合稳定能力；
- `make()` — 每次新实例，适合命令、输入对象、一次性对象。

### 命名槽 {#named-slots}

一个契约要对应多份实例时，用 `Switon\Di\Factory`：**属性名即槽名**，配置决定各槽填什么。

仅当名称来自运行时数据时，才用 `NamedLookupInterface`。

### 别名重定向 {#alias-redirects}

`#[Autowired]` 也接受显式 ID 与相对别名：

- `#cache` → `Type#cache`；
- 完整服务 ID 可在接线处绕过属性名。

别名适合**接线边缘**，不要当成业务代码里的常规写法。

### 作用域 {#scope}

DI 单例是**进程级**的。可变请求或连接状态应放在 `ContextAware` + `ContextManagerInterface`，不要堆在共享服务属性上。

### 诊断 {#diagnostics}

```bash
bash bin/console container:inspect --id 'App\Service\ProductService'
bash bin/console container:definitions --filter 'App\Service'
bash bin/console container:instances --filter 'App\Service'
```

---

## 配置 {#configuration}

多数应用只在约定不够用时才写配置：

- 实现类不在同命名空间；
- 属性需要标量覆盖；
- 一个契约要多个命名实例。

### 显式绑定 {#explicit-binding}

```yaml
# switon.yml
App\Contracts\StorageInterface:
  class: App\Service\S3Storage
```

### 命名实例 {#named-instances}

```yaml
# switon.yml
App\Service\CacheInterface:
  class: Switon\Di\Factory
  default:
    uri: redis://localhost/0
  cache:
    uri: redis://localhost/1
```

再按**属性名**注入对应槽。

---

## 使用注意 {#keep-in-mind}

- `new` 手建会跳过 `#[Autowired]`；
- `?Type` **不会**让对象注入变成可选；要延迟解析用 `Type|Lazy`；
- 不要把容器当请求级状态仓库；
- DI 负责服务、对象、命名槽与可调用参数解析；若要在最终调用外包守卫/拦截器，见 [Invoker 包](../invoker/README.md#invoker-package)；
- 运行时名称优先走 `NamedLookupInterface`，业务里少硬编码服务 ID。

---

## 下一步 {#next}

- **属性注入规则：** [Autowired](autowired.md)
- **命名实例：** [Factory](factory.md)
- **常见问题：** [FAQ](faq.md)
- **设计取舍：** [设计说明](design.md)
- **教程：** [教程](../tutorial/README.md#tutorial)
