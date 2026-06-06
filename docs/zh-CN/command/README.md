---
sidebar_label: Command 包
description: switon/command：命令发现与元数据，供 CLI 与 Beacon 共用。
---

# Command 包 {#command-package}

`switon/command` 是 Switon 命令类的共享发现与元数据层。CLI 靠它渲染 `list`、帮助与短选项别名；Beacon 靠它把动作简介暴露给 AI 工具。只有在你直接需要这些元数据时才选这个包；多数应用通过 CLI 或 Beacon 间接使用。

**关键特性：**

- **共享发现：** `Switon\Command\CommandDiscoveryInterface::discover()` 返回缓存的「命令名 → 类」映射。
- **基于反射的检视：** `Switon\Command\CommandInspectorInterface` 从 PHPDoc 与反射读取命令/动作摘要、参数说明与选项元数据。
- **可见性元数据：** `Switon\Command\Attribute\Hidden` 在默认列表中隐藏类或动作，但不阻止执行。
- **工具描述：** `Switon\Command\Attribute\Tool` 为动作附加面向 AI 的短简介。
- **显式短别名：** `Switon\Command\Attribute\ShortOption` 在默认首字母缩写会冲突时固定单字母别名。

## 安装 {#installation}

**环境要求：** PHP 8.3+

```bash
composer require switon/command
```

## 快速开始 {#quick-start}

照常写命令类。只在 CLI 列表或 AI 工具需要的地方加元数据。

```php
namespace App\Command;

use Switon\Command\Attribute\Hidden;
use Switon\Command\Attribute\ShortOption;
use Switon\Command\Attribute\Tool;

/**
 * Report commands.
 */
final class ReportCommand
{
    /**
     * Show one report summary.
     *
     * @param string $format Output format.
     * @param bool $force Rebuild even when the current result looks fresh.
     */
    #[Tool('Returns one report summary in the requested format.')]
    public function statusAction(string $format = 'json', #[ShortOption('F')] bool $force = false): array
    {
        return ['format' => $format, 'force' => $force];
    }

    #[Hidden]
    public function rebuildAction(): void
    {
    }
}
```

`format` 与 `force` 都以 `f` 开头；`#[ShortOption('F')]` 为 `force` 保留 `-F`，避免别名冲突。

## 核心概念 {#core-concepts}

### 命令形态 {#command-shape}

一个 `*Command` 类是一族命令。公有 `*Action()` 是可运行入口；`defaultAction()` 是裸命令的惯用名（CLI 路由在 `switon/cli`）。

### 发现 {#discovery}

发现只返回缓存的名字到类的映射，不做别的事。

- 应用命令来自路径扫描（`App\Command\*Command`、`App\Areas\<Area>\Command\*Command`）。
- 包命令在 `composer.json` 的 `extra.switon.commands` 登记。
- `discover()` 不会向运行时注册执行器；CLI 与 Beacon 才是消费方，负责接好执行。
- 内建 CLI 命令会一直留在映射里，即便 `composer-extra` 数据缺失或读不出。

### 检视 {#inspection}

`CommandInspectorInterface` 从反射与 PHPDoc读取：

- 类 docblock 的首句 → 命令摘要；
- 动作 docblock 的首句 → 动作摘要；
- `@param` 文案 → 参数帮助；
- `getActions()` 默认只返回可见动作；传 `true` 可包含带 `#[Hidden]` 的；
- `getActionAiDoc()` 返回 `#[Tool]` 文案或 `null`。

### 属性叠加层 {#attribute-overlays}

- 类或方法上的 `#[Hidden]`：默认列表不展示；按全名仍可运行。
- 动作上的 `#[Tool('...')]`：附一句 AI 简介，交代「做什么」即可。
- 参数上的 `#[ShortOption('x')]`：固定短别名；本包只承载元数据，由 CLI 解析 argv 时应用。

## 使用注意 {#keep-in-mind}

- 本包只管元数据。调用、帮助渲染与 Shell 补全在 `switon/cli`。
- `#[Hidden]` 隐藏的是 `list` 条目，不是执行能力。CLI 里排查「找不到命令」时先试 `list --all`。
- 若未发现，先查类名后缀，再查扫描路径，最后查 `extra.switon.commands`。

## 下一步 {#next}

- **运行命令类：** [CLI 包](../cli/README.md)
- **把动作暴露为 AI 工具：** [Beacon 包](../beacon/README.md)
- **常见问题：** [FAQ](faq.md#command-faq)
- **取舍与设计：** [设计说明](design.md#design-rationale)
