---
description: 从控制台暴露已有应用逻辑，使同一条业务路径无需经过 HTTP 即可运行。
---

# 添加 CLI {#add-cli}

当你希望从控制台暴露已有应用逻辑，使同一条业务路径无需经过 HTTP 即可运行时，使用本指南。

## 边界 {#execution-boundary}

从与实际工作匹配的触发模型起步。

| 边界 | 最适合 | 注意 |
|---|---|---|
| [CLI](../cli/README.md) | 人、脚本或运维应直接运行一条命令 | 手动入口的最佳默认；业务规则留在 service，而非命令方法 |
| [Schedule](../schedule/README.md) | 同一工作应按时间间隔运行 | 通常在命令路径已清晰且可运行之后再添加 |
| [Executor](../executor/README.md) | 主要问题是 runner 式执行、分组或依赖运行时的提交行为 | 比第一条手动命令路径更适合执行编排 |

一种常见的应用形态是：

- **CLI** 用于手动或脚本触发的入口
- **Schedule** 当同一命令或 service 应按时间规则运行
- **Executor** 当编排或 runner 执行语义成为真正难点

## 构建顺序 {#build-order}

1. 仅在业务动作已落在 service 中之后再开始（控制器不应再承载业务规则）。
2. 在已扫描路径（如 `App\Command`）下添加**一个**命令类；自定义布局时先把第一条命令放在默认可发现位置。
3. 让命令处理 CLI 输入/输出，业务规则留在 service。
4. 尽早验证：`list`、一次真实运行、`--help`。
5. 在分支到 [Schedule](../schedule/README.md) 或 [Executor](../executor/README.md) 之前停下。

## 最小接线 {#minimal-wiring}

仅用短代码展示共享 service 与 CLI 入口之间的接缝。

下文中的 `TaskServiceInterface`、`TaskController`、`TaskCommand` 等应用级名称仅为示意。Switon 框架符号与命令约定才是稳定部分。
若应用使用 `/api` 等全局路由前缀，控制器映射保持无前缀，并用 `bash bin/console router:prefix` 确认真实公开 URL。

### 共享 service 保持不变 {#wiring-service}

本指南的要点不是把业务工作移入 CLI 层。保持一条共享 service 边界：

```php
namespace App\Service;

interface TaskServiceInterface
{
    public function complete(int $id): array;
}
```

你的 HTTP 控制器可继续像以前一样调用该 service：

```php
namespace App\Controller;

use App\Service\TaskServiceInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Routing\Attribute\PostMapping;

#[RequestMapping('')]
class TaskController
{
    #[Autowired] protected TaskServiceInterface $taskService;

    #[PostMapping('/tasks/{id}/complete')]
    public function completeAction(int $id): array
    {
        return ['task' => $this->taskService->complete($id)];
    }
}
```

若应用添加 `/api` 等全局前缀，即使控制器映射仍是 `'/tasks/{id}/complete'`，公开路由也会变成 `/api/tasks/{id}/complete`。

### 在同一 service 外增加一条命令类 {#wiring-command}

在可扫描的命令路径下增加一个常规命令类：

```php
namespace App\Command;

use App\Service\TaskServiceInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;

class TaskCommand
{
    #[Autowired] protected TaskServiceInterface $taskService;
    #[Autowired] protected ConsoleInterface $console;

    public function completeAction(int $id): void
    {
        $task = $this->taskService->complete($id);
        $this->console->success('Completed task {id}.', ['id' => $task['id'] ?? $id]);
    }
}
```

默认情况下，`TaskCommand::completeAction()` 会成为 `task:complete` 命令名。
规则是机械的：

- 从类名去掉 `Command` 后缀，再转为 kebab-case：`TaskCommand` -> `task`
- 从方法名去掉 `Action` 后缀，再转为 kebab-case：`completeAction` -> `complete`
- 拼接为 `command:action`

这样 CLI 层保持精简：

- 命令决定**运维如何调用**
- service 决定**应用做什么**
- 控制台输出确认**发生了什么**

### 运行并验证 {#wiring-run}

在应用根目录使用：

```bash
bash bin/console list
bash bin/console task:complete 42
bash bin/console task:complete --help
```

若应用由 `switon/skeleton` 创建，`bin/console` 已是该布局的一部分，并包装与 `switon.php` 相同的 CLI kernel。
若项目未提供 `bin/console`，按 [CLI 快速开始](../cli/README.md#fallback-entry) 中的备用入口形态操作。
若命令未出现，先检查类位置（`App\Command` 或其他扫描路径），再检查命名规则（带公开 `*Action()` 方法的 `*Command`）。

第一条成功线很小：
- 发现机制工作
- 一个 action 能运行
- 同一 service 现已可从 HTTP 与 CLI 复用

## 陷阱 {#pitfalls}

有意让第一条 CLI 路径保持狭窄。

过早避免：

- **把业务规则移入命令方法** — 命令应协调控制台输入与输出，而非拥有应用规则
- **在正常 DI 已适用时手动伸进容器** — 继续使用类型化依赖与 `#[Autowired]`
- **在一条命令能工作之前设计整套运维面** — 先证明一个有用入口
- **把第一条命令放在常规发现路径之外** — 初学者路径保持在 `App\Command`，除非应用已约定其他扫描位置
- **在手动命令尚不清晰时跳到 `Schedule` 或 `Executor`** — 触发模型变化在第一条可运行路径之后
- **把第一条命令当作工具平台** — `#[Tool]`、隐藏命令、补全与更丰富的清单是后续议题
- **把成功/失败消息变成完整日志架构** — 仅为首个运维环增加足够反馈

完成第一条 CLI 路径后，初学者应能感到：

> 我知道一条 service 如何在不把应用逻辑移入命令层的情况下，从控制台复用。

## 下一步 {#go-deeper}

- **在第一条 API 与已存数据路径之后衔接：** [构建 API](build-an-api.md#build-an-api) · [持久化数据](persist-data.md#persist-data)
- **按章节学习同样动作：** [05 — CLI 复用](../tutorial/05-cli.md#05-cli)
- **深入普通命令类与发现：** [CLI](../cli/README.md#quick-start) · [Discovery](../cli/README.md#discovery) · [Visibility](../cli/README.md#visibility)
- **保持 service 接线清晰且接口优先：** [DI](../di/README.md#quick-start)
- **下一步为命令失败增加最小可见性：** [06 — 日志与失败](../tutorial/06-log.md#06-log) · [Log](../log/README.md#quick-start)
- **从手动命令进入周期性工作：** [添加周期性任务](add-recurring-work.md#add-recurring-work) · [定时清理](../tutorial/timed-cleanup.md#timed-cleanup) · [Schedule](../schedule/README.md)
- **编排执行而非内联完成工作：** [Executor](../executor/README.md)
