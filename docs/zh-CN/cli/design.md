---
sidebar_label: CLI 设计说明
description: 手动运维面与应用同形、小契约叠加帮助与补全。
---

# 设计说明 {#design-rationale}

`switon/cli` 把手动操作面留在与 HTTP、worker、监听器相同的应用形状里，而不是散成 shell 脚本和各式各样的命令对象。

## 参见 {#see-also}

- [CLI 包](README.md#cli-package) —— 用法、发现、补全与运维要点
- [FAQ](faq.md#cli-faq) —— 常见问题
- [Command 包](../command/README.md) —— 发现元数据与属性
- [Console 包](../console/README.md) —— 终端原语与帮助背后的能力

---

## 核心问题 {#core-problem}

团队都需要维护、修复、导入、清理和运维向工作流。没有共同形态时，入口会和应用其它部分脱节：

- 有的变成 shell 脚本，有的变成另一套命令对象约定；
- 有的直接掏容器，有的自建选项解析；
- AI/工具说明和人类 CLI 表面对不上。

框架需要一条手动入口边界，复用同一套 DI、输入与发现故事。

## 首要目标 {#primary-goal}

`switon/cli` 先固定一种很小的命令形态，再在其上叠帮助、发现与补全。

- **好处：** 运维动作好找、好审、好复用。
- **好处：** 简单命令往往一个类加一个方法就够。
- **代价：** 大型工作流引擎、远程序运行器不在默认包的责任里。

## 核心取舍 {#core-choice}

一个类一族命令；公有 `*Action()` 是可运行入口。

- **路由：** `command` 或 `command:action`。空 argv 与 `--help` → `list`；`command --help`、`command:action --help` → `help`。
- **发现：** 应用代码走路径映射（`App\Command\*Command`、`App\Areas\*\Command\*Command`）；包代码走 `composer.json` 里的 `extra.switon.commands`。
- **绑定：** 具名选项优先，其余位置参数按声明顺序填入。

对外调用契约保持小，命令类内部仍照常 DI。

## 取舍 {#trade-off}

包不提供工作流引擎、远程序或完整工具注册表。

- **好处：** 心智模型小：一个类、一段 PHPDoc、一套约定。
- **代价：** 需要编排、远地执行或重工具元数据时，要在 CLI 之上再叠一层。

## 刻意保持简单 {#deliberate-simplicity}

可见性、AI 元数据与补全都是叠加层，不是另一套模型。

- `#[Hidden]` 管列表可见性；命令仍可运行。
- `#[Tool]` 给工具消费者附描述；CLI 调用不变。
- 补全由当前发现集与反射选项生成，不是冻结目录。

不用的应用可以完全忽略这些叠加层，CLI 仍然完整可用。
