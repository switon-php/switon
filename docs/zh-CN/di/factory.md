---
sidebar_label: Factory
description: Switon\Di\Factory：属性名槽位、default、# 别名与 NamedLookup。
---

# `Factory` {#factory}

`Switon\Di\Factory` 把一个契约映射到**多个命名实例**；裸类型解析到 `#default`。

**关键特性：**

- **属性名即槽：** `$cache` → `Type#cache`；
- **默认回退：** 只写 `Type` 时用 `#default`；
- **槽别名：** 可用 `#name` 让一槽指向另一槽；
- **运行时查找：** 名称来自数据时用 `NamedLookupInterface`；
- **独立绑定：** 命名槽与主 `Type` 绑定分开管理。

---

## 快速开始 {#quick-start}

```yaml
# switon.yml
Switon\Redis\ClientInterface:
  class: Switon\Di\Factory
  default:
    uri: redis://localhost:6379/0
  cache:
    uri: redis://localhost:6379/1
  session:
    uri: redis://localhost:6379/2
```

```php
use Switon\Core\Attribute\Autowired;
use Switon\Redis\ClientInterface;

class MyService
{
    #[Autowired] protected ClientInterface $redis;
    #[Autowired] protected ClientInterface $cache;
    #[Autowired] protected ClientInterface $session;
}
```

`$redis` 走 `default`；`$cache`、`$session` 与属性名同名槽对应。

---

## 核心概念 {#core-concepts}

### 槽位匹配 {#slot-matching}

- 属性名 = 槽名；
- 先匹配 `Type#propertyName`；
- 仅写 `Type` 时回退 `#default`；
- 无 `default` 且名称对不上时**快速失败**。

### 显式槽重定向 {#explicit-slot-redirects}

属性名与槽名不一致时，在类配置里写 `#name`：

```yaml
App\Service\MyService:
  redis: '#cache'
```

### 运行时查找 {#runtime-lookup}

- 名称来自运行时数据 → `NamedLookupInterface`；
- 槽在代码里已固定 → 属性注入更简单。

---

## 使用注意 {#keep-in-mind}

- `remove(Type::class)` **不会**删掉 `Type#name` 条目；
- 无 `default` 时，未匹配的属性名会失败；
- 稳定槽名不要滥用 `NamedLookupInterface`。

---

## 下一步 {#next}

- **包总览：** [DI 包](README.md)
- **属性注入：** [Autowired](autowired.md)
- **FAQ：** [FAQ](faq.md)
- **设计说明：** [设计说明](design.md)
