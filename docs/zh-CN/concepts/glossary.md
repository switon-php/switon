---
sidebar_label: 术语表
description: Switon 文档中反复出现术语的简明释义，面向首次读者与辅助翻译阅读。
---

# 术语表 {#glossary}

Switon 文档中反复出现的术语的简明含义，尤其面向首次读者与借助翻译阅读的人。

## 如何使用这些术语 {#terms}

三条规则：

- 整份文档对同一英文术语保持**一种本地语言译法**
- 若本地语言没有简短自然对应，首次出现时保留英文并附简短说明
- 优先实用措辞，而非最正式措辞

目标很简单：让读者能正确行动，而不必反复解码同一概念。

## 核心术语 {#core-terms}

### 应用启动与 runtime {#startup-runtime}

| 术语 | 简明含义 | 记住 |
|---|---|---|
| **bootstrap** | 正常应用工作开始前的启动阶段 | 在此准备 env、config、services |
| **runtime** | 当前运行应用的通道 | 常见为 HTTP、CLI、schedule、worker |
| **scene** | 当前 runtime 的短标签 | 例：`http`、`cli`、`schedule` |
| **owner doc** | 拥有完整细节的包文档 | guides 与 tutorial 指向 owner doc，而非复制全文 |

### 请求与 service 流 {#request-flow}

| 术语 | 简明含义 | 记住 |
|---|---|---|
| **boundary** | 一项职责结束、另一项开始的位置 | guide 说「选对 boundary」时，读作「把代码放对地方」 |
| **transport boundary** | 接收或发送传输相关数据的外层入口 | 例：controller action、CLI command、queue worker |
| **service** | 拥有业务工作的可复用类 | 同一 service 应可从多个入口调用 |
| **service boundary** | 传输代码把业务交给 service 的交接点 | 这使 controller 与 command 保持薄 |
| **lifecycle listener** | 在已知阶段运行的请求级 HTTP 逻辑 | 例：request id、CORS、auth 加载、访问日志 |
| **controller interceptor** | 包裹一个 controller 或一个 action 的逻辑 | 用于局部 HTTP 行为，而非整请求策略 |
| **event listener** | 应用代码发出某一 typed 事件类后的进程内反应 | 当后续反应应与主路径分离时使用 |
| **request input object** | 由请求数据构建的 typed 输入对象 | 使请求输入清晰且已校验 |
| **handoff** | 一层把所需工作或数据交给下一层的一刻，通常经方法调用或入队 | 常见：controller → service、service → queue |

### 状态与后台工作 {#background-work}

| 术语 | 简明含义 | 记住 |
|---|---|---|
| **request attributes** | 挂在一个 HTTP request 对象上的值 | 用于简单、仅 HTTP 的交接 |
| **Context** | 为一个 service 提供的请求级状态对象 | 当共享 service 在一次逻辑请求中需要可变状态时使用 |
| **worker** | 在正常请求路径外处理后台工作的进程 | 常比一次 HTTP 请求存活更久 |
| **recurring work** | 按时间规则运行的工作 | 例：夜间清理、每小时报表 |
| **durable background work** | 交给队列或能熬过请求失败/延迟的传输的工作 | 当任务必须重试或稍后完成时使用 |
| **escape hatch** | 默认捷径不够时的更明确、更低层路径 | 当作工具，而非起点 |
| **shape** | 解决方案的整体形态 | 白话里「application shape」通常指大图结构 |
| **surface** | 你直接打交道的部分 | 例：config surface、公共 API surface |

## 翻译提示 {#translation-tips}

翻译中最易混淆的词：

- **`boundary`** 通常指**一项职责结束、另一项开始的位置**，或简单说**代码该放哪**，不是物理边界
- **`shape`** 通常指**整体结构**，不是几何形状
- **`surface`** 通常指**开发者直接接触的层面**，不是视觉表面
- **`handoff`** 通常指**把工作/数据交给下一层**，不是人际交接仪式
- **`escape hatch`** 通常指**明确的退路/备选路径**，不是错误状态

若句子仍显抽象，缩成这个问题：

> 这里的代码要做什么？该放在哪里？

这个问题通常能给出最清晰的本地改写。
例如「move it to the service boundary」可本地化为「把这段可复用业务代码放在 service 层，不要放在 controller」。
