---
sidebar_label: Sharding 包
description: switon/sharding：规则路由、unique/multiple/all 与 modulo/list/range 策略。
---

# Sharding 包 {#sharding-package}

`switon/sharding` 依据紧凑规则将读写映射到分片连接与表。

**关键特性：**

- **规则驱动分片选择：** 从上下文值推导分片名，而非硬编码分支。
- **小型规则语法：** 支持 `db:user_id%4`、`user:user_id%8` 等简写。
- **显式分片选择：** `unique()`、`multiple()` 与 `all()` 覆盖常见路由模式。
- **内置策略：** `modulo`、`list`、`range`、`crc32` 与 `hash`。
- **快速失败校验：** 非法规则、多分片单目标请求与不安全 join 尽早失败。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+，以及提供核心工具与事件的框架组件（`switon/core`、`switon/event`）。

```bash
composer require switon/sharding
```

---

## 快速开始 {#quick-start}

```php
use Switon\Core\Attribute\Autowired;
use Switon\Sharding\ShardingManagerInterface;

class UserRepository
{
    #[Autowired] protected ShardingManagerInterface $shardingManager;

    public function find(int $userId): array
    {
        return $this->shardingManager->unique(
            'db:user_id%4',
            'user:user_id%8',
            ['user_id' => $userId],
        );
    }
}
```

---

## 核心概念 {#core-concepts}

### 路由模式 {#routing-modes}

- `unique()` 返回**一个**连接与**一张**表。
- `multiple()` 返回所有匹配的分片。
- `all()` 忽略上下文，返回**每个**已配置分片。

### 规则 {#rules}

- 仅支持 `base`、`base:field%N`、`base:0,1,2` 与 `base:field:strategy:config`。
- `base:field%N` 要求字段名符合 PHP 标识符规则。
- `base:field:strategy:config` 为完整形式；其它冒号数量无效。
- 分片名格式为 `base_suffix`；modulo/类 hash 规则在除数为 `1` 时返回不带后缀的 `base`。

### 策略 {#strategies}

- `modulo` 适用于数值分片键。
- `crc32` 与 `hash` 适用于字符串分片键。
- `hash` 接受 `N` 或 `algo,N`；除数字符串前导 `0` 可启用零填充后缀。
- `range` 适用于**闭区间**数值区间。
- `list` 适用于预定义分片集合，且将每一项格式化为 `base_item`。

### 分片上下文 {#context}

- 数组与对象均可作为分片上下文。
- 缺失上下文可能扇出到全部分片。
- 对象通过 `get_object_vars()` 读取。
- 对 `unique()`，数字索引数组被视为**多个**上下文。
- `unique()` 拒绝解析到**多于一个**分片的上下文。

### 边界 {#boundaries}

- **不支持**跨分片 join。
- **不在**本包范围内：分布式事务。
- 分片**只**解析名称；**不**创建数据库或表。
- 不安全的分片 join 由 query 层校验 — 见 [Query 包](../query/README.md#sharding)。
- 部分查询模式会抛出 `Switon\Sharding\Exception\ShardingNotSupportedException`。

---

## 配置 {#configuration}

多数应用只需管理器及其策略集合。

```yaml
Switon\Sharding\ShardingManagerInterface:
  strategies:
    modulo: Switon\Sharding\Strategy\ModuloStrategy
    list: Switon\Sharding\Strategy\ListStrategy
    range: Switon\Sharding\Strategy\RangeStrategy
    crc32: Switon\Sharding\Strategy\Crc32Strategy
    hash: Switon\Sharding\Strategy\HashStrategy
```

可用自有 `StrategyInterface` 实现替换或扩展 `strategies`。

### 选项发现 {#option-discovery}

```bash
bash bin/console config:options 'Switon\Sharding\ShardingManagerInterface'
```

---

## 使用注意 {#keep-in-mind}

- 缺失分片键可能扇出。
- `list` **忽略**上下文中的分片键值。
- 当表侧解析不到分片时，`multiple()` 返回 `[]`。
- `range` 区间外的值解析为**无分片**。
- 调整分片数量会使 hash 类策略**重映射**已有行。
- 对 `unique()` 传入**空**上下文数组会快速失败。

---

## 下一步 {#next}

- **快速答疑：** [FAQ](faq.md#sharding-faq)
- **设计取舍：** [设计说明](design.md#design-rationale)
- **ORM 实体映射：** [ORM 分片指南](../orm/sharding.md#sharding-guide)
- **查询层：** [Query 包](../query/README.md)
