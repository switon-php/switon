---
sidebar_label: Console 包
description: switon/console：进度条、终端宽度、ANSI Colors 等终端原语。
---

# Console 包 {#console-package}

`switon/console` 为 Switon 应用提供可复用的终端原语：进度条、终端宽度检测与 ANSI 颜色标志。适合命令或 worker 需要统一的进度与样式层、而不是每个应用各写一套时使用。

**关键特性：**

- **进度条：** `Switon\Console\ProgressBarInterface`（默认实现 `Switon\Console\ProgressBar`）自动启动，把进度限制在配置上限内，并渲染已用与剩余时间。
- **宽度检测：** 终端宽度取自 `COLUMNS` 环境变量，由各渲染层自行读取，不做 shell 探测。
- **ANSI 样式标志：** `Switon\Console\Colors` 提供前景、背景与文本属性的位掩码常量。
- **小契约：** 进度与宽度接口保持精简，便于替换实现而不改调用点。

## 安装 {#installation}

**环境要求：** PHP 8.3+

```bash
composer require switon/console
```

本包只提供原语，不注册应用绑定。`switon/cli` 会在写命令时把它们接好线。

## 快速开始 {#quick-start}

进度注入 `ProgressBarInterface`；着色时把 `Colors` 与 `Switon\Core\ConsoleInterface` 的实现配合使用。

```php
namespace App\Service;

use Switon\Console\Colors;
use Switon\Console\ProgressBarInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;

class ImportUsers
{
    #[Autowired] protected ProgressBarInterface $progressBar;
    #[Autowired] protected ConsoleInterface $console;

    public function run(array $rows): void
    {
        $this->progressBar->start(count($rows));

        foreach ($rows as $row) {
            $this->importRow($row);
            $this->progressBar->advance();
        }

        $this->progressBar->finish();
        $this->console->writeLn(
            $this->console->colorize('Import complete', Colors::FC_LIGHT_GREEN | Colors::AT_BOLD)
        );
    }
}
```

## 核心概念 {#core-concepts}

### 进度条 {#progress-bars}

`ProgressBar` 是面向单次终端任务的有状态、原地输出原语。

- `start(?int $max = null)` 开始渲染；省略 `max` 时默认为 `1`。
- `advance($step = 1)`、`setProgress($current)`、`finish()` 在尚未启动时会自动调用 `start()`。
- `setProgress()` 会夹到当前 `max`；`finish()` 写最后一帧并追加换行。
- `setFormat()` 可用占位符：`%current%`、`%max%`、`%percent%`、`%bar%`、`%elapsed%`、`%remaining%`、`%rate%`。
- `setMessage()` 在渲染行上附加短说明。

### 终端宽度 {#terminal-width}

宽度取自 `COLUMNS` 环境变量（交互式 shell 会导出）。各渲染层读取一次并缓存结果；没有 shell 探测，也没有共享的 detector 服务。

- `COLUMNS` 未设置或非数字时回退到 `120` 列。
- 低于 `20` 视为不可用，回退到默认值。
- 非交互运行时可显式设置环境变量（或 `putenv('COLUMNS=120')`）来固定宽度。

### ANSI 颜色 {#ansi-colors}

`Colors` 把 ANSI 前景、背景与属性整理成整数码，供 `Switon\Core\ConsoleInterface::colorize()` 使用。用 `|` 组合。本包只提供常量；实际渲染由 `ConsoleInterface` 实现完成（例如 `Switon\Cli\Console`）。

## 使用注意 {#keep-in-mind}

- `ProgressBar` 面向单次联机终端流程。多个并发任务共用一个实例会导致输出交错。
- 宽度读自 `COLUMNS`，终端缩放后进程不重新读取该变量就会保持启动时的宽度。
- `Colors` 是位掩码，不是命名主题；需要命名样式时在应用里建预设。

## 下一步 {#next}

- **在命令里输出、询问与着色：** [CLI 包](../cli/README.md)
- **常见问题：** [FAQ](faq.md#console-faq)
- **取舍与设计：** [设计说明](design.md#design-rationale)
