# 术语 {#terms}

`docs/zh-CN/**` 的内部本地化支撑文件。

将本页作为简体中文技术写作的**术语基线**：面向高频或高风险用词，保持简短；仅当同一用词反复导致漂移时再更新。本文件**不属于**组件三件套（`README.md` / `faq.md` / `design.md`），勿当作对外包导航。

## 规则 {#rules}

- **勿翻译**代码型标识：FQCN、方法名、配置键、CLI 命令、常量、路径、URL 与锚点。
- **保持克制**：只记重复出现、影响取舍的术语。
- **每个概念一个稳定说法**，除非确实存在必要区分。
- 若某组件需要更窄的措辞，写在组件文档里，勿在本表堆叠例外。

## 核心术语 {#core-terms}

| English | 中文建议 | 避免 / 注意 | 备注 |
|---|---|---|---|
| `bootstrap` | 引导 / `bootstrap` | 在需要强调顺序时与 `startup` 混用 | 多指框架初始化阶段或路径。 |
| `startup` | 启动 / `startup`、应用启动、启动路径 | 与 `bootstrap` 在「次序」语境下混用 | `startup` 更宽；`bootstrap` 是其中的初始化阶段。 |
| `runtime` | 运行时 / `runtime`、运行时边界 | 在指执行环境时用含混的「模式」等词 | FPM / Swoole / CLI / 自定义运行时等语境。 |
| `boundary` | 边界 / 信任边界、校验边界 | 对同一设计边界换不相关的词 | 需要时用修饰语说清是哪种边界。 |
| `fallback` | 回退 / `fallback` | 软化成含混的「兜底」「另一种」 | 多指主路径缺失或失败时的次级路径。 |
| `payload` | 载荷 / `payload` | 在 JWT/HTTP/序列化形态重要时改成非技术表述 | 技术语境保留 `payload`。 |
| `binding` | 绑定 / `binding` | 与 `wiring`、`mapping` 随意互换 | DI 或参数绑定时用 `binding`，除非语境需要更窄词。 |
| `provider` | 提供者 / `provider`、服务提供者 | 译成业务词如「供应商」 | 保持框架 technical 含义。 |
| `worker` | 工作进程 / `worker`、`worker process` | 弱化「进程生命周期」含义 | 强调进程存活期时用 `worker process`。 |
| `dispatch` | 派发 / `dispatch` | 在事件系统动作上用临时同义词 | 与 `dispatch()` 等稳定事件用语对齐。 |
| `event dispatcher` | 事件派发器 / `event dispatcher` | dispatcher / bus / emitter 混指同一概念 | 与 `Psr\EventDispatcher\EventDispatcherInterface` 一致。 |
| `fail-fast` | 快速失败 / `fail-fast` | 冗长改述模糊契约 | 强调应尽早硬失败时用。 |
| `service-definition map` | 服务定义映射 / `service-definition map` | 在强调结构时扁平说成「配置」 | 指合并后的 DI 定义结构时适用。 |
| `service ID` | 服务 ID / `service ID` | 与「服务名」「键」混用 | 容器 id 场景下保持明确。 |
| `collector` | 收集器 / `collector` | 随意引入新架构标签 | 仅当配置项确为 collector 角色时使用。 |
