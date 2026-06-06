---
sidebar_label: ORM Codegen 包
description: switon/orm-codegen：entity:make 从表生成 Entity/Repository 与输出模式。
---

# ORM Codegen 包 {#orm-codegen-package}

`switon/orm-codegen` 从数据库表生成 `Entity` 与 `Repository` 类。

**关键特性：**

- **一条命令写两类文件：** `entity:make` 同时生成实体与匹配的 Repository；
- **可先预览：** `entity:spec`、`entity:list` 为预览辅助命令；
- **命名风格：** `--naming=camel` 改属性名并加 `#[NamingStrategy(NamingStrategy::CAMEL)]`；
- **输出位置：** `auto` / `app` / `runtime` 控制写入目录。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+、`switon/orm`、`switon/db`、`switon/command`、`switon/console`、`switon/di`、`switon/core`

```bash
composer require --dev switon/orm-codegen
```

---

## 快速开始 {#quick-start}

在应用根目录执行 `entity:make`。

> **说明：** Windows 上可用 `php switon.php ...` 等等价命令。

```bash
bash bin/console entity:make users --connection=default
```

省略表名则生成该连接下**所有表**：

```bash
bash bin/console entity:make --connection=default
```

需要 camelCase 属性名：

```bash
bash bin/console entity:make users --connection=default --naming=camel
```

同时会在实体上添加 `#[NamingStrategy(NamingStrategy::CAMEL)]`。

---

## 核心概念 {#core-concepts}

### 生成流程 {#generation-flow}

- `entity:make` **写入**文件；
- 已有实体↔表映射会**复用**。

### 预览命令 {#preview-commands}

- `entity:list` 为隐藏命令；用 `bash bin/console list --all` 发现；
- `entity:spec` 展示将生成的类名、主键与属性。

### 输出模式 {#output-mode}

由 `OutputDirectoryResolver` 控制：

| 模式 | 行为 |
|------|------|
| `auto` | `@app/Entity` 除共享基类 `Entity.php` 外无业务实体 → 写 `@app`，否则 → `@runtime/orm` |
| `app` | 始终 `@app` |
| `runtime` | 始终 `@runtime/orm` |

`auto` 下：仅有 `@app/Entity/Entity.php` 视为「空」；有子目录或其它实体文件则改写到 `@runtime/orm`。

---

## 配置 {#configuration}

### 实体扫描路径 {#entity-scan-paths}

若实体不在默认目录，在 `switon.yml` 配置 `Switon\OrmCodegen\EntityScanner.paths`：

```yaml
Switon\OrmCodegen\EntityScanner:
  paths:
    - '@app/Entity/*.php'
    - '@app/Areas/*/Entity/*.php'
    - '@app/Domain/*/Entity/*.php'
```

只添加**确实包含 ORM 实体**的路径。

---

## 使用注意 {#keep-in-mind}

- `entity:make` 会**重新生成** Entity / Repository（覆盖目标文件）；
- 已有实体文件中的类常量会**保留**；
- **已有映射优先**于新推断；
- `entity:spec` / `entity:list` 隐藏，需 `list --all` 发现。

---

## 下一步 {#next}

- **常见问题：** [FAQ](faq.md#orm-codegen-faq)
- **设计取舍：** [设计说明](design.md#design-rationale)
- **运行时 ORM 契约：** [ORM 包](../orm/README.md)
