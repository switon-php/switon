# 跨运行时的一体应用模型 {#one-app-model-across-runtimes}

Switon 在 `HTTP`、`CLI`、计划任务与 worker 式运行时路径上保持同一应用形态：

- `Switon\Kernel\Kernel` 引导一次
- 容器拥有共享服务
- 入口保持薄
- 业务工作保持可复用

**为何是亮点：**
- **一个引导基座** — `Switon\Http\Kernel` 与 `Switon\Cli\Kernel` 都扩展 `Switon\Kernel\Kernel`，而非各自发明启动模型
- **薄的运行时边界** — 控制器、命令、计划任务与 worker 循环是入口，不是业务规则应堆积之处
- **共享服务层** — 同一应用服务可从请求处理器、控制台命令、计划任务或 worker 路径复用
- **稳定的 worker 交接** — 周期与后台工作可留在 `Switon\Core\RunnerInterface` 与 `Switon\Executor\ExecutorInterface` 上，而非为每个运行时生长一种执行模型

## 分散的问题 {#the-scattered-problem}

许多应用会慢慢分裂成不同形态：

- HTTP 一种服务风格
- CLI 命令各自长出逻辑
- 计划工作变成包装脚本
- worker 在队列专属代码后重新实现相同业务规则

这使应用行为更难测试、追踪与修改，因为每个运行时不再像同一应用。

## Switon 模式 {#the-switon-pattern}

Switon 让常见形态更小：

- `Switon\Kernel\Kernel` 准备 env、config、DI 与 fatal 处理
- 运行时边界接管，例如 `Switon\Http\Kernel`、`Switon\Cli\Kernel`、`#[Scheduled]` 任务执行，或使用 `Switon\Queue\QueueInterface` 的 worker 循环
- 该边界快速委托给共享应用服务或 `Switon\Core\RunnerInterface` 实现

重要的不是每个运行时使用相同 API 表面。
重要的是它们复用同一引导契约与同一服务层。

## 什么保持可复用 {#what-stays-reusable}

该模型让运行时专属代码保持狭窄：

- HTTP 边界聚焦请求输入与响应输出
- CLI 边界聚焦控制台输入与操作者反馈
- 计划任务聚焦基于时间的触发
- worker 循环聚焦拉取与消费工作

业务操作本身可留在一个服务中，或在作业天然适合 runner 形态时留在一条 `RunnerInterface` 路径上。

## 边界 {#boundaries}

这**并不**意味着每个运行时应被强行塞进同一抽象。

- 对 HTTP 服务关切使用 `Switon\Http\Kernel`。
- 对命令入口使用 `Switon\Cli\Kernel`。
- 当时间规则才是真实触发时使用 `switon/schedule`。
- 仅当后台传输或回复语义才是真实边界时使用 `switon/queue`、`switon/rpc` 或 worker 循环。

共享模型是在这些运行时差异之下保持一种应用结构，而非抹平差异。

## 另见 {#see-also}

- **在框架心智模型中放置此点：** [理解应用模型](../concepts/application-model.md)
- **决定什么应留在控制器、命令、任务与 worker 中：** [理解服务边界](../concepts/service-boundaries.md)
- **理解长生命周期运行时的单例安全：** [理解运行时与状态](../concepts/runtime-and-state.md)
- **在选择运行时路径前从首次证明开始：** [入门](../getting-started/README.md)
- **深入了解共享引导：** [Kernel 包](../kernel/README.md)
- **添加手动运行时入口：** [CLI 包](../cli/README.md) 与 [添加 CLI](../guides/add-cli.md)
- **添加周期性运行时入口：** [Schedule 包](../schedule/README.md) 与 [添加周期性任务](../guides/add-recurring-work.md#add-recurring-work)
- **添加 worker 式持久后台工作：** [Queue 包](../queue/README.md) 与 [添加持久后台工作](../guides/add-durable-background-work.md)
