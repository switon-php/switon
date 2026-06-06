---
sidebar_label: CLI 包
description: switon/cli：命令类、选项绑定、帮助、发现与 Shell 补全。
---

# CLI 包 {#cli-package}

`switon/cli` 让 Switon 应用有一套统一的命令面：普通命令类、选项绑定、帮助输出、发现与补全。适合把运维入口、维护脚本和周期任务做成和应用其它部分同一套形状，而不是散落成一堆临时脚本。

**关键特性：**

- **普通命令类：** 一个 `*Command` 类就是一族命令；公有 `*Action()` 方法即入口。
- **稳定的调用形态：** 执行 `command` 或 `command:action`；`command --help` 与裸 `--help` 走内建路由。
- **参数绑定：** 动作参数先按具名选项绑定，再按声明顺序用位置参数补齐。
- **内建命令：** `list`、`help`、`completion:install` 随包提供；不需要预生成目录。
- **可见性元数据：** `Switon\Command\Attribute\Hidden` 让支持向动作不出现在默认 `list` 里，但仍可运行。
- **AI/工具元数据：** `Switon\Command\Attribute\Tool` 为工具注册附着稳定描述。

## 安装 {#installation}

**环境要求：** PHP 8.3+

```bash
composer require switon/cli
```

骨架默认带 `bin/console`。没有骨架时可用很小的入口脚本拉起 CLI kernel，见 [备用入口](#fallback-entry)。

## 快速开始 {#quick-start}

把 `*Command` 类放在扫描路径下，通过 `bin/console` 调用。

```php
namespace App\Command;

use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;

class ReportCommand
{
    #[Autowired] protected ConsoleInterface $console;

    /**
     * Show one daily report summary.
     *
     * @param string $date Report date in Y-m-d format.
     */
    public function dailyAction(string $date = '2026-05-03'): void
    {
        $this->console->success('Report ready for {date}.', ['date' => $date]);
    }

    /**
     * Rebuild one snapshot.
     *
     * @param int $id Snapshot id.
     * @param bool $force Rebuild even when the snapshot looks fresh.
     */
    public function rebuildAction(int $id, bool $force = false): void
    {
        $this->console->info('Rebuilt snapshot {id}.', ['id' => $id]);
    }
}
```

```bash
bash bin/console list
bash bin/console report:daily --date=2026-05-03
bash bin/console report:rebuild --id=42 --force
bash bin/console report:daily --help
```

### 备用入口 {#fallback-entry}

没有 `bin/console` 时，可用极小 PHP 入口（例如 `switon.php`）启动同一 kernel：

```php
require __DIR__ . '/vendor/autoload.php';

(new Switon\Cli\Kernel())->start();
```

直接执行该脚本，例如 `php switon.php list`。

## 核心概念 {#core-concepts}

CLI 靠四条线保持可预期：命令形态、动作路由、发现、可见性。

### 形态与路由 {#shape-and-routing}

类名映射为命令名，方法名映射为动作名。

- `ReportCommand` → `report`；`BackupDatabaseCommand` → `backup-database`。
- `defaultAction()` → 裸 `command`；`createAction()` → `command:create`；`createBackupAction()` → `command:create-backup`。
- 空 argv 与 `--help` 走 `list`。`command --help`、`command:action --help` 走 `help`。

### 发现 {#discovery}

应用命令来自 `App\Command\*Command` 与 `App\Areas\<Area>\Command\*Command`。包命令在 `composer.json` 的 `extra.switon.commands` 登记，经 `switon/composer-extra` 读出。两路合并进同一解析器。

### 参数绑定 {#parameter-binding}

先绑具名选项，剩下位置参数按声明顺序填入。

```php
class UserCommand
{
    public function createAction(string $email, string $name, string $role = 'user'): void
    {
    }
}
```

```bash
bash bin/console user:create --email=john@example.com --name=John
bash bin/console user:create john@example.com John
bash bin/console user:create --role=admin john@example.com John
```

两个参数可能在首字母缩写冲突时用 `Switon\Command\Attribute\ShortOption` 拆开。

### 可见性 {#visibility}

`#[Hidden]` 让命令或动作不出现在默认 `list` 输出里；仍可按全名调用。

```php
use Switon\Command\Attribute\Hidden;

class ReportCommand
{
    #[Hidden]
    public function rebuildIndexAction(): void
    {
    }
}
```

`bash bin/console list --all` 会列出隐藏项。

### 内建工具面 {#built-in-tooling}

- `bash bin/console list` 与 `bash bin/console list --json`：人读与机器读的清单。
- `bash bin/console help <command>[:<action>]`：单动作帮助；`--help` 走同一套渲染。
- `bash bin/console completion:install` 写补全脚本；`completion:check` 校验安装。
- 注入 `Switon\Core\ConsoleInterface` 做输出与询问；注入 `Switon\Console\ProgressBarInterface` 做进度条。

## 使用注意 {#keep-in-mind}

- `defaultAction()` 保留裸 `command`；没有它时，单独敲 `command` 会退回帮助。
- 隐藏不是「丢了命令」。搜代码前先 `list --all`。
- `#[Tool]` 是叠加层，不改变写命令或调用的方式。

## 下一步 {#next}

- **把服务接到控制台：** [接入 CLI](../guides/add-cli.md#add-cli)
- **跑定时或 cron 风格任务：** [Schedule 包](../schedule/README.md)
- **引导 kernel 与配置：** [Kernel 包](../kernel/README.md)
- **常见问题：** [FAQ](faq.md#cli-faq)
- **取舍与 rationale：** [设计说明](design.md#design-rationale)
