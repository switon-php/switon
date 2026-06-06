---
sidebar_label: Composer Extra 包
description: switon/composer-extra：把包 extra 元数据收成运行时缓存，供发现列表使用。
---

# Composer Extra 包 {#composer-extra-package}

`switon/composer-extra` 把已安装包的 `extra` 元数据落成一份缓存文件，运行时通过一次 DI 注入的服务读取，而不在每次请求里碰 Composer 内部。适合需要框架级发现（providers、commands、listeners、tasks、jobs）既快又稳的场景。

**关键特性：**

- **运行时读取器：** `Switon\ComposerExtra\ComposerExtraInterface` 通过注入的服务读生成好的缓存。
- **发现列表：** `collect()` 遍历各包合并后的 `extra` 里 `extra.switon.*` 路径，返回有序字符串列表。
- **Composer 刷新钩子：** `post-install-cmd` 与 `post-update-cmd` 自动重建缓存。
- **本地覆盖：** path 安装的包在开发期可带上当前 `composer.json` 的元数据。
- **尽力刷新：** 写缓存失败会报告，但不会中止 Composer。

## 安装 {#installation}

**环境要求：** PHP 8.3+、`ext-json`、Composer 2.0+、`composer-plugin-api` ^2.0。

```bash
composer require switon/composer-extra
```

Composer 2.2+ 还要求在根项目里允许插件，见 [配置](#configuration)。

## 快速开始 {#quick-start}

注入 `ComposerExtraInterface`，用 `collect()` 做发现列表，或 `get()` / `all()` 读原始 `extra` 数据。

```php
namespace App\Service;

use Switon\ComposerExtra\ComposerExtraInterface;
use Switon\Core\Attribute\Autowired;

final class PackageRegistry
{
    #[Autowired] protected ComposerExtraInterface $composerExtra;

    public function providers(): array
    {
        return $this->composerExtra->collect('switon.providers');
    }

    public function cacheHealth(): array
    {
        return $this->composerExtra->health();
    }
}
```

## 核心概念 {#core-concepts}

### 缓存流程 {#cache-flow}

插件在 Composer 钩子里写缓存；运行时只读。

- `post-install-cmd` 与 `post-update-cmd` 调用插件。
- 插件从 Composer 元数据读取每个已安装包的 `extra`。
- 缓存写到 `vendor/switon/composer-extra.json`。
- `Switon\ComposerExtra\ComposerExtra` 首次读取时惰性加载该文件并在内存中复用。
- 对 path 安装的包，加载缓存时合并实时 `composer.json` 的覆盖，本地改动无需再跑一遍 Composer 也能体现。

### 读取面 {#reader-surface}

- `get(string $packageName): array` —— 单个包的 `extra`，缺失时为 `[]`。
- `all(): array` —— 全量包映射；缓存文件缺失或无效时抛出异常。
- `has(string $packageName): bool` —— 存在性检查；空 `extra` 返回 `false`。
- `health(): array` —— 不抛错的状态：`ok`、`reason`、`cacheFile`、`packageCount`（`reason` 取值：`ok`、`cache_missing`、`cache_unreadable`、`invalid_json`、`invalid_shape`、`cache_empty`）。

### 发现列表 {#discovery-lists}

`collect(string $path)` 在各包合并后的 `extra` 里按点分路径走一遍，返回扁平的非空字符串列表。

```php
$providers = $composerExtra->collect('switon.providers');
$listeners = $composerExtra->collect('switon.listeners');
```

- 叶子可以是单个字符串，或字符串数组（有序列表或字符串键映射；**键名忽略**）。
- 非字符串值跳过；空路径段忽略。
- 按包迭代顺序追加；**重复值保留**。

## 配置 {#configuration}

没有 `switon.yml` 块。多数项目只需在根 `composer.json` 里允许插件：

```json
{
  "config": {
    "allow-plugins": {
      "switon/composer-extra": true
    }
  }
}
```

## 使用注意 {#keep-in-mind}

- 不要手改 `vendor/switon/composer-extra.json`；下次 install/update 会覆盖。
- 框架发现类元数据在 `extra.switon.*` 下用 `collect()`。应用级类仍归各组件自己的扫描器或配置。
- 安装/更新钩子被跳过或 path 包覆盖异常时，`health()` 是最快的信号。

## 下一步 {#next}

- **缓存失败与边角：** [FAQ](faq.md#composer-extra-faq)
- **取舍与设计：** [设计说明](design.md#design-rationale)
