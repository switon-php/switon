---
sidebar_label: 应用模型
description: 启动、配置、应用元数据与运行时入口的统一心智模型，再聚焦单一功能。
---

# 理解应用模型 {#application-model}

在只盯一个功能之前，先用一套心智模型理解启动、配置、应用元数据与运行时入口。

## 术语速览 {#terms}

若有术语陌生，边读边开着 [面向初学者与译者的术语表](glossary.md)。

- **bootstrap** — 在 runtime 工作开始前加载 env、config 与 service 的初始化阶段
- **runtime** — 执行通道，例如 HTTP、CLI、schedule 或 worker
- **area** — 应用内按功能分组，通常在 `app/Areas/<Area>/`
- **module** — 可连贯的功能单元，可留在应用内，也可成为 package/plugin
- **app metadata** — 应用身份与环境值，如 id、env、debug、timezone
- **scene** — 当前 runtime 标签，如 `http`、`cli`、`schedule`
- **service-definition map** — 由 `switon.yml` 与可选 PHP config 补丁构成的有效 DI/配置定义

## 先有一套心智模型 {#mental-model}

从这条框架形状入手：

1. **`Kernel` 启动应用**并准备共享底座。
2. **加载环境与配置**，使应用拥有一份有效定义图。
3. **容器解析 service**，包 provider 完成 bootstrap 收尾。
4. **某条 runtime 通道接管** — 例如 HTTP 处理请求，CLI 跑 command，worker 消费后台任务。
5. **应用代码通过 service 做业务**，而不是通过 bootstrap 代码或传输层全局量。

因此同一 service 层可从 controller、command、定时任务或 worker 复用。

## 启动与配置 {#startup-and-configuration}

正常启动时，`Kernel` 是在 runtime 专属逻辑继续之前，准备 env、config、DI 与致命错误处理的边界。

先记住两条规则：

- **`switon.yml` 是主应用配置面**
- **`config/*.php` 在 YAML 不够用时作补充**

环境值在读取配置之前加载，因此构建应用定义时 `${VAR}` 插值已就绪。系统环境变量优先于 `.env.local` 与 `.env`。

由此得到稳定模型：

- env 决定部署相关值
- `switon.yml` 描述常态应用形状
- 可选 PHP config 处理 YAML 不好表达的项，例如计算值、条件注册或小型程序化补丁

权威细节见 [Kernel](../kernel/README.md) 与 [Core 包](../core/README.md#core-concepts)。

## 应用元数据与 runtime scene {#app-runtime-scene}

Switon 把**应用身份**与**当前 runtime 通道**分开。

- **`Switon\Core\AppInterface`** — 应用元数据：id、env、debug、timezone 等
- **`Switon\Core\SceneManagerInterface`** — 当前 runtime scene：`http`、`cli`、`schedule` 等

多数应用 service 只需要元数据，不需要当前 runtime 标签。代码若必须随通道反应，用更窄的 `SceneManagerInterface`。

实用默认：

- 普通应用代码优先 `AppInterface`
- 只有确实需要 scene 感知行为时才用 `SceneManagerInterface`

owner 文档见 [Core 包](../core/README.md#app-metadata-vs-scene)。

## Provider 与应用代码 {#providers-application-code}

常见混淆是把 bootstrap 接线与普通应用代码混在一起。

按此拆分：

- **Service provider** — 包接线与 bootstrap 钩子
- **`switon.yml`** — 应用覆盖与稳定配置
- **应用 service** — 使用 `#[Autowired]` 与普通包契约

因此多数应用代码不应从 provider 类、静态容器访问或手写 bootstrap 钩子起步。若类属于正常应用行为，第一个问题通常不是「改哪个 provider？」而是「该由哪条 service 边界拥有？」

见 [Core](../core/README.md)、[DI](../di/README.md) 与 [Kernel — service providers](../kernel/README.md#service-providers)。

## 典型应用形状 {#typical-application-shape}

小型 Switon 应用通常像这样：

1. **Bootstrap** — `Kernel` 加载 env、config、service
2. **Runtime 入口** — HTTP、CLI、scheduler 或 worker 路径接管
3. **传输边界** — controller、command 或 worker 入口接收输入
4. **业务边界** — service 执行真实应用工作
5. **基础设施边界** — 在功能需要处使用持久化、cache、mail、queue、storage

要点不在包列表长短，而在 runtime 入口保持薄、业务代码可复用。

功能超出扁平 `app/` 树时，先按 area 分组应用自有代码，再考虑可复用 package。放置模型见 [理解模块与 Area](modules.md)。
