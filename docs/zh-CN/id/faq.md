---
sidebar_label: ID FAQ
description: ID：命名注入、Snowflake/Redis、gmp 与 ORM 策略名映射。
---

# ID FAQ {#id-faq}

面向搭建、选型与运行时问题的短答。

> **前置：** [ID 包](README.md#id-package)

## 入门 {#getting-started}

### 怎么生成 ID？ {#generate-id}

注入 `IdGeneratorInterface`，用属性名选生成器。见 [快速开始](README.md#quick-start)。

### 怎么一次生成多个？ {#generate-batch}

同一接口上调用 `nextN($count)`。

### 该从哪个生成器开始？ {#which-generator}

可排序字符串多用 **`uuid7`**；整数 ID 且有 Redis 用 **`snowflake`**；短公开 token 用 **`nanoId`**。见 [如何选择](README.md#choosing-a-generator)。

### 一个服务里怎么用多种生成器？ {#multiple-generators}

每个名字一个属性，类型提示可相同：

```php
use Switon\Core\Attribute\Autowired;
use Switon\Id\IdGeneratorInterface;

class AssetService
{
    #[Autowired] protected IdGeneratorInterface $uuid7;
    #[Autowired] protected IdGeneratorInterface $nanoId;
}
```

见 [命名注入](README.md#named-injection)。

### 怎么得到更短的 UUID 字符串？ {#short-uuid}

用 `ShortUuid::generate()`、`encode()`、`decode()` 等静态方法；不可注入，需 **`ext-gmp`**。

### 为什么 `RequestIdFilter` 用 UUID v4？ {#request-id-filter}

请求 ID 过滤器使用未命名的默认 `IdGeneratorInterface` 绑定，即 **`uuid4`**。

## 配置 {#configuration}

### 生成器在哪配？ {#configure}

在 `switon.yml` 用 `Switon\Id\IdGeneratorInterface#snowflake` 等服务 id。见 [配置](README.md#configuration)。

### 怎么看可配置项？ {#discover-options}

查正在调整的具体类，例如 `Switon\Id\Snowflake`。

```bash
bash bin/console config:options 'Switon\Id\Snowflake'
```

`config:options 'Switon\Id\IdGeneratorInterface'` 只显示未命名默认（**`uuid4`**）。

## 排错 {#troubleshooting}

### Snowflake 运行时失败 {#snowflake-fails}

多为 Redis 后端缺失或异常。确认 `redis` 解析为 **1 或 2 个** `ClientInterface` 实例。无法分配序列时抛 `SequenceBackendUnavailableException`。

### UUID v4 / nanoId 插入变慢 {#random-pks}

随机 ID 降低索引局部性；高热主键优先 **`uuid7`** 或 **`snowflake`**。

### Ksuid / ShortUuid 报错 {#gmp-runtime}

二者做进制转换需要 **`ext-gmp`**，安装后重试。

### ORM 策略名如何对应？ {#orm-strategy}

`uuid` → `uuid4`，`uuid-v7` → `uuid7`。

### Snowflake 的 Redis 怎么配？ {#snowflake-redis}

`redis` 选项为 1～2 个 `Switon\Redis\ClientInterface` 服务。Snowflake 是唯一需要 Redis 协调的内置生成器。
