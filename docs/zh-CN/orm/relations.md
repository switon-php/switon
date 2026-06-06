---
sidebar_label: 关联
description: ORM 关联元数据在实体、加载形状在 Repository fields。
---

# 关联指南 {#relation-guide}

实体类声明关联**元数据**；Repository 调用决定加载**哪张关联图**。

> **阅读前提：** [ORM 包](README.md)

**关键特性：**

- **实体拥有元数据；**
- **Repository 拥有加载形状；**
- 支持一对一、一对多、穿透、多对多、联结、多态等族。

## 支持的形状 {#supported-shapes}

- `BelongsTo`、`HasOne`、`HasMany`、`HasManyThrough`
- `HasManyToMany`、`BelongsToMany`（语义别名）
- `JunctionMany`、`MorphTo`、`MorphMany`

---

## 加载 {#loading}

通过 Repository 的 `fields` 参数加载：

```php
$users = $this->users->all([], [
    'id',
    'name',
    'posts' => ['id', 'title'],
]);
```

`fields` 形态：

- `[]` — 关联默认字段集；
- 数组 — 选字段与嵌套子关联；
- `callable` — 自定义关联查询。

**未在调用中请求的关联，属性访问不会事后加载。**

---

## 常见形状 {#common-shapes}

### 一对一 {#one-to-one}

`BelongsTo` 多对一侧；`HasOne` 反侧一对一。

### 一对多 {#one-to-many}

`HasMany`，可配 `orderBy` 等。

### 多对多 {#many-to-many}

`HasManyToMany` / `BelongsToMany`，经联结实体。

### 穿透与联结 {#through-and-junction}

`HasManyThrough` 经中间实体；`JunctionMany` 以联结实体本身为关联模型。

### 多态 {#polymorphic}

`MorphTo` / `MorphMany` 基于表名 + id 字段；`MorphTo` 依赖**稳定**的表名字符串。

---

## 使用注意 {#keep-in-mind}

- 元数据在实体；加载在 Repository；
- `BelongsToMany` 行为同 `HasManyToMany`；
- 勿依赖属性懒加载。

---

## 下一步 {#next}

- **实体元数据：** [实体指南](entity.md)
- **Repository API：** [ORM 包](README.md)
- **为何显式加载：** [设计说明](design.md)
