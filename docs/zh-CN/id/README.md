---
sidebar_label: ID 包
description: switon/id：IdGeneratorInterface 命名绑定、UUID/ULID/Snowflake 与 ShortUuid。
---

# ID 包 {#id-package}

`switon/id` 为 Switon 应用提供**单一生成器契约**，通过 DI **命名绑定**选用 UUID、ULID、Snowflake 与紧凑公开 ID 等实现。

**关键特性：**

- **统一契约：** 注入 `Switon\Id\IdGeneratorInterface`，由**属性名**选择生成器；
- **内置实现：** `uuid4`、`uuid7`、`ulid`、`snowflake`、`nanoId`、`ksuid`、`mongoId`、`cuid2`；
- **稳定默认：** 未命名 `IdGeneratorInterface` 解析为 `uuid4`；
- **短 UUID：** `ShortUuid` 静态辅助，22 字符 Base58 UUID 文本；
- **Snowflake 运行时：** 唯一需要 **Redis** 协调序列的内置生成器。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+、`switon/redis`、`switon/sync`

使用 `Ksuid` 或 `ShortUuid` 时需安装 `ext-gmp`。

```bash
composer require switon/id
```

---

## 快速开始 {#quick-start}

注入 `IdGeneratorInterface`，用属性名选生成器。多数字符串 ID 从 **`uuid7`** 开始；要默认绑定则保持接口**未命名**。

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Id\IdGeneratorInterface;

class OrderService
{
    #[Autowired] protected IdGeneratorInterface $uuid7;
    #[Autowired] protected IdGeneratorInterface $idGenerator;

    public function create(): string
    {
        return $this->uuid7->next();
    }
}
```

`$uuid7` 解析到 `Switon\Id\IdGeneratorInterface#uuid7`。  
`$idGenerator` 解析到未命名默认，即 `uuid4`。

同一服务需要多种生成器时，**每个名字一个属性**。

---

## 核心概念 {#core-concepts}

### 命名注入 {#named-injection}

`Switon\Id\ServiceProvider` 在 `Switon\Id\IdGeneratorInterface#name` 下注册命名绑定。

```php
class UserService
{
    #[Autowired] protected IdGeneratorInterface $snowflake;
    #[Autowired] protected IdGeneratorInterface $uuid7;
    #[Autowired] protected IdGeneratorInterface $nanoId;
}
```

内置命名槽：`snowflake`、`uuid4`、`uuid7`、`ulid`、`nanoId`、`ksuid`、`mongoId`、`cuid2`。未命名的 `IdGeneratorInterface` 经 `#default` 解析到 `#uuid4`。

### 如何选择生成器 {#choosing-a-generator}

按需要的 ID 形态选型：

| 场景 | 建议 |
|------|------|
| 多数可排序字符串 ID | `uuid7` |
| 可排序整数 ID（有 Redis） | `snowflake` |
| 短公开 token | `nanoId` |
| 可读有序字符串 | `ulid` |
| 标准随机 UUID | `uuid4` |
| 27 字符、按时间排序的 Base62 串（需 `ext-gmp`） | `ksuid` |
| ObjectId 兼容 | `mongoId` |
| 紧凑随机风格文本 | `cuid2` |
| 已有 UUID 要更短可逆形式 | `ShortUuid`（静态） |

### 生成器 API {#generator-api}

`IdGeneratorInterface` 仅暴露 `next()`、`nextN()`；算法特有方法留在具体类或静态辅助上。

### ShortUuid {#shortuuid}

不可注入；需要更短 UUID 文本时用静态方法，而不是再注册一个生成器服务。

---

## 配置 {#configuration}

在 `switon.yml` 调整命名绑定。多数生成器默认可用；仅在有可调项时再加配置。

`Snowflake`：`shard`、`prefix`、`prefetch`、`jitter`、`redis`；`nanoId` / `cuid2`：`alphabet`、`length`。

```yaml
# switon.yml
Switon\Id\IdGeneratorInterface#snowflake:
  shard: 1
  prefetch: 200

Switon\Id\IdGeneratorInterface#nanoId:
  length: 12

Switon\Id\IdGeneratorInterface#cuid2:
  length: 28
```

---

## 选项发现 {#option-discovery}

查看**命名**生成器的选项时用具体类：

```bash
bash bin/console config:options 'Switon\Id\Snowflake'
bash bin/console config:options 'Switon\Id\NanoId'
bash bin/console config:options 'Switon\Id\Cuid2'
```

`bash bin/console config:options 'Switon\Id\IdGeneratorInterface'` 只显示未命名默认绑定。

---

## 使用注意 {#keep-in-mind}

- 除非明确要整数，多数数据库 ID 用 `uuid7`。
- 选 `snowflake` 前先规划 Redis。
- `Ksuid` 与 `ShortUuid` 需要 `ext-gmp`。
- 随机 ID 适合不透明 token，不适合高热主键。
- 对有可调项的生成器，`config:options` 查询**具体生成器类**，不是接口。
- ORM 策略名映射不同：`uuid` → `uuid4`，`uuid-v7` → `uuid7`；其它名称与槽位一致。
- `Switon\Http\Filter\RequestIdFilter` 使用未命名的 `IdGeneratorInterface`（即 `uuid4`）生成 `X-Request-Id`。

---

## 下一步 {#next}

- **取舍与边界：** [设计说明](design.md#design-rationale)
- **常见问题：** [FAQ](faq.md#id-faq)
- **Snowflake 与 Redis 分配：** [Redis 包](../redis/README.md)
- **命名属性与 DI：** [Autowired](../di/autowired.md#autowired)
- **在仓储与实体中使用 ID：** [ORM 包](../orm/README.md#orm-package)
