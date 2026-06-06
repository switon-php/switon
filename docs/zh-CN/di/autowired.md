---
sidebar_label: Autowired
description: #[Autowired]：注入时机、标量/数组/Lazy、instances 与显式重定向。
---

# `#[Autowired]` {#autowired}

`#[Autowired]` 在**构造函数逻辑运行之前**注入类型化属性。

**关键特性：**

- **接口优先：** 同命名空间 `XxxInterface` → `Xxx` 自动解析；
- **构造前可用：** `__construct()` 里已能读到注入值；
- **多种形式：** 服务、标量、数组、`Switon\Core\Lazy`；
- **数组实例：** `instances: true` 把数组项解析成服务对象；
- **显式重定向：** `#name` 与完整服务 ID 可覆盖槽位。

---

## 快速开始 {#quick-start}

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Redis\ClientInterface;

class ProductService
{
    #[Autowired] protected ClientInterface $redis;
    #[Autowired] protected int $ttl = 3600;
}
```

```yaml
# switon.yml
App\Service\ProductService:
  ttl: 7200
```

会注入 `ClientInterface`，并在构造前应用标量覆盖。

---

## 核心概念 {#core-concepts}

### 注入时机 {#injection-timing}

属性在构造函数之前注入。`new` **不会**触发注入。

### 自动装配规则 {#autowiring-rules}

同命名空间 `XxxInterface` + `Xxx` 时通常免配置。

需要显式配置的情况：

- 实现类在别的命名空间；
- 类型名与实现类名不一致；
- 一个契约对应多种实现。

### 值的形式 {#value-forms}

- 服务对象；
- 标量；
- 数组；
- `Switon\Core\Lazy`。

数组：配置与默认值**合并**；`null` 删除键；`Traversable` **整体替换**。要让数组元素是服务实例而非类名字符串，用 `instances: true`。

### 显式接线 {#explicit-wiring}

- `#name` — 属性重定向到 `Type#name`；
- 完整服务 ID — 目标必须写死时用。

---

## 使用注意 {#keep-in-mind}

- `new` 跳过注入；
- 无类型声明无法自动装配；
- 有接口时默认应依赖接口，不要直接绑具体类；
- `?Type` 不等于「可选注入」；
- 延迟解析用 `Type|Lazy`，不要用可空对象类型糊弄。

---

## 下一步 {#next}

- **包总览：** [DI 包](README.md)
- **命名实例：** [Factory](factory.md)
- **FAQ：** [FAQ](faq.md)
- **设计说明：** [设计说明](design.md)
