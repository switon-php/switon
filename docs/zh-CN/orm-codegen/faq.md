---
sidebar_label: ORM Codegen FAQ
description: ORM Codegen：entity:make、auto 输出、模板与扫描路径。
---

# ORM Codegen FAQ {#orm-codegen-faq}

**代码生成相关的短答。**

> **阅读前提：** [ORM Codegen 包](README.md)

## 入门 {#getting-started}

### 应先跑哪条命令？ {#which-command}

写文件用 **`entity:make`**；只想先看映射/规格用 `entity:list` 或 `entity:spec`。

### 如何生成连接下全部表？ {#all-tables}

`bash bin/console entity:make --connection=default`

### 如何只生成一张表？ {#single-table}

第一个参数传表名，如 `entity:make users --connection=default`。

### `--naming=camel` 改什么？ {#naming-camel}

改生成**属性名**并在实体上加 `NamingStrategy::CAMEL`；**不改**数据库表名。

### 为什么普通 `list` 看不到 `entity:spec`？ {#hidden-inspection-actions}

`entity:spec`、`entity:list` 为隐藏命令；用 `list --all`。

## 生成行为 {#generation-behavior}

### 类名怎么定？ {#class-name-resolution}

表已有关联实体 → 复用其命名空间与类名；否则从表名推断。

### 为什么写到 `@runtime/orm` 而不是 `@app`？ {#runtime-app}

`auto` 在 `@app/Entity` 除共享 `Entity.php` 外已有实体或子目录时，改写到 `@runtime/orm`，避免污染已有应用树。

### 一次成功生成会写什么？ {#generation-output}

通常各一个 Entity、Repository；若存在 `@root/docs/entities.md` 会同步表清单。

### 会覆盖已有文件吗？ {#overwrite-target-files}

**会**。长期定制应放在**模板**，而非每次生成后手改生成文件。

## 配置 {#configuration}

### 实体不在默认 `@app/Entity`？ {#scan-paths}

配置 `EntityScanner.paths`。

### 如何覆盖模板？ {#override-templates}

放置 `@app/templates/generator/Entity.sword` 与 `Repository.sword`，或在 `config/generator.php` 设显式路径；内置模板扩展名为 `.sword`。

### 怎么看可配置项？ {#inspect-options}

`config:options 'Switon\OrmCodegen\EntityScanner'`、`config:options 'Switon\OrmCodegen\Generator\OutputDirectoryResolver'`。

## 排错 {#troubleshooting}

### 连错库？ {#wrong-connection}

常见原因：未传预期的 `--connection`，回退到 `default`。

### 类名/命名空间不符合预期？ {#unexpected-name}

多为**已有映射**已胜出；先查映射，再查扫描路径。

### 生成形状要长期自定义？ {#custom-shape}

改**模板**，不要反复手改生成结果。
