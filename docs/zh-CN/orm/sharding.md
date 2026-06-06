---
sidebar_label: 分片
description: ORM 分片：Connection/Table 表达式、写路径单一目标与 createMany。
---

# 分片指南 {#sharding-guide}

分片路由写在**实体元数据**上；Repository 写入前解析目标。

**关键特性：**

- `#[Connection]`、`#[Table]` 承载路由规则；
- **写路径**须唯一分片：`create`/`update`/`delete`/`put`/`createMany`；
- **读路径**可扇出；
- `createMany()` 要求同一实体类型且同一已解析分片；
- ORM **只路由**，不建库表。

---

## 快速开始 {#quick-start}

```php
#[Connection('db:user_id%4')]
#[Table('orders:user_id%8')]
class Order extends Entity
{
    #[Id]
    public ?int $order_id = null;

    public int $user_id;
    public float $amount;
}
```

未分片时用普通名如 `default`、`orders`。

---

## 核心概念 {#core-concepts}

### 路由输入 {#routing-inputs}

`Connection` 定库目标；`Table` 定表目标；可为普通名或分片表达式。

### 读写路径 {#write-paths}

`ShardingInterface` 四种形状：

- `getAnyShard()` — 任可用目标；
- `getUniqueShard()` — **写**须唯一（跨库/表则失败）；
- `getMultipleShards()` — 读可扇出；
- `getAllShards()` — 全展开。

### 批量写 {#batch-writes}

`createMany()` 接受**一种**实体且**一个**分片；行可能落不同分片时先按分片键分组。

### 事务 {#transactions}

`#[Transactional]` 适合整段工作流在同一分片；可能跨分片时按分片组拆分。

---

## 配置 {#configuration}

分片元数据仍须在 `switon.yml` 配置真实 `ClientInterface`：

```yaml
Switon\Db\ClientInterface:
  class: Switon\Di\Factory
  db_0:
    uri: '<dsn-for-db_0>'
  db_1:
    uri: '<dsn-for-db_1>'
```

---

## 使用注意 {#keep-in-mind}

- 须单一目标的写调用前带上分片驱动字段；
- 可能跨分片的 `createMany()` 先按分片键拆分；
- ORM 只路由到**已存在**目标；
- 延迟敏感读路径优先带分片键约束。

---

## 下一步 {#next}

- **实体与键：** [实体指南](entity.md)
- **Repository 持久化：** [ORM 包](README.md)
- **规则语法与底层 API：** [Sharding 包](../sharding/README.md)
- **跨分片查询：** [Query 包](../query/README.md)
- **常见问题：** [FAQ](faq.md)
- **设计说明：** [设计说明](design.md)
