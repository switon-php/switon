---
description: 将可工作的 Switon 应用形成稳定的生产形态：运行时、配置、持久化资源与独立的 worker 进程。
---

# 将应用部署到生产环境 {#deploy-an-app-to-production}

当一条 Switon 应用已能工作，且需要在运行时、配置、持久化资源与 worker 进程之间形成稳定生产形态，而又不必把容器平台、蓝绿发布或舰队自动化当作首要答案时，使用本指南。

## 选对生产边界 {#production-boundary}

从本地工作与生产运维之间实际会变化的那条边界起步。

| 边界 | 最适合 | 注意 |
|---|---|---|
| [Kernel](../kernel/README.md) | 问题是 env、YAML 与应用元数据从何而来 | 系统 env 优先于 `.env.local` 与 `.env`；`switon.yml` 仍是主配置面 |
| [HTTP 服务器](../http/server.md) | 必须选择 HTTP 适配器、代理形态与重启策略 | `type: php` 仅用于开发；生产选 `swoole` 或 `fpm` |
| [Storage](../storage/README.md) | 上传、生成文件或 blob 必须在部署或清理后仍存在 | `@runtime` 适合运行时自有文件，不适合长期用户数据 |
| [Redis](../redis/README.md) | 队列、锁、缓存或 session 流量需要真实支撑服务策略 | 起步一个共享客户端即可；仅在工作负载确实不同时再拆分命名客户端 |
| [Schedule](../schedule/README.md) | 周期性工作已存在，且必须在生产中可靠运行 | HTTP 服务器**不会**替你启动定时任务 |
| [Queue](../queue/README.md) / [RPC](../rpc/README.md) | 后台或请求/回复 worker 必须在请求生命周期之外运行 | 将 worker 循环作为由运维单独管理的进程 |
| [Log](../log/README.md) / [Inspector](../inspector/README.md) | 需要生产信号，又不想一直开着开发式调试 | 先保持一个稳定的日志目标；Inspector 仅用于受控调试 |

一种常见的应用形态是：

- **Kernel** 可预测地加载 env 与配置
- **HTTP** 通过 `swoole` 或 `fpm` 服务请求
- **Storage** 使文件与部署生命周期正确匹配
- **Redis** 承载缓存、锁或队列传输等共享基础设施
- **Schedule**、**Queue** 或 **RPC** 作为独立的长生命周期或周期性进程
- **Log** 作为首要运维信号，**Inspector** 作为可选诊断工具，而非正常生产路径的必需部分

## 建议的构建顺序 {#build-order}

1. 仅在至少一条真实应用路径已在本地正确之后再开始；不要用部署掩盖未完成边界。
2. 保持清晰的配置故事：`switon.yml`、可选 `config/*.php`、系统 env（必要时 `SWITON_CONFIG_FILE`）。
3. 显式设置 `AppInterface` 元数据（`env`、`debug`、`timezone` 等）与路由器 `prefix`。
4. 在调代理之前选定生产 HTTP 适配器（`swoole` 或 `fpm`，**不要**生产使用 `type: php`）。
5. 把必须跨部署保留的用户/上传文件放到持久路径，不把 `@runtime` 当长期数据根。
6. 将 HTTP、[Schedule](../schedule/README.md)、[Queue](../queue/README.md)/[RPC](../rpc/README.md) worker 作为独立进程运行，勿假定 HTTP 进程会替你启动后台环。
7. 选定一个日志目标与生产调试姿态（容器常选 stdout；级别通常 `info`；[Inspector](../inspector/README.md) 保持受控使用）。
8. 在公开流量前验证路由、前缀、`schedule:list`、worker 与 Redis 等依赖从部署环境可达。

## 最小接线 {#minimal-wiring}

仅用简短片段展示配置、进程形态与安全生产默认之间的接缝。

下文中的应用 id、挂载路径、Redis URI 等应用级取值仅为示例。Switon 框架符号、配置键与命令面才是稳定部分。

### 保持精简的生产向应用与 HTTP 基线 {#wiring-app-http}

从一份明确生产意图的小型 `switon.yml` 起步：

```yaml
# switon.yml (project root)
Switon\Core\AppInterface:
  id: myapp
  name: My Application
  env: ${APP_ENV:-prod}
  debug: false
  timezone: ${APP_TIMEZONE:-UTC}

Switon\Routing\RouterInterface:
  prefix: /api

Switon\Http\ServerOptions:
  type: swoole
  host: 0.0.0.0
  port: 9501
  settings:
    worker_num: 8
    max_request: 100000
```

该基线让三件事保持清晰：

- 除非部署刻意覆盖，应用知道自己运行在生产
- 在客户端或代理依赖之前，公开路由前缀已固定
- HTTP 运行时是明确的生产选择，而非开发内置服务器

若部署围绕 PHP-FPM，改用 `type: fpm`。
若在 Nginx 后运行 Swoole，复用 [服务器与部署 — Nginx 反向代理](../http/server.md#nginx) 中的代理边界，而不是另造一套 HTTP 形态。

### 显式配置持久文件与 Redis 客户端 {#wiring-storage-redis}

将文件与共享支撑服务放到生产自有路径与 URI 上：

```yaml
# switon.yml
Switon\Storage\BlobStoreInterface:
  root: '<mounted-path>/uploads'

Switon\Redis\ClientInterface:
  class: Switon\Di\Factory
  default:
    uri: redis://127.0.0.1:6379/0
  queue:
    uri: redis://127.0.0.1:6379/2?pool_size=32

Psr\Log\LoggerInterface:
  level: info
  appenders:
    file: Switon\Logging\Appender\FileAppender
```

这样首次生产边界保持可读：

- 必须在部署后存活的存储离开 `@runtime`
- Redis 仍可从一个默认客户端起步，工作负载需要时队列流量可再拆分
- 日志基线显式且易于检查

若部署在容器优先环境，将主日志目标改为 `Switon\Logging\Appender\StdoutAppender`，而不是沿用宿主机文件假设。

### 用相同的公开入口验证部署 {#wiring-verify}

在真实流量之前，区分**一次性检查**与**长生命周期 worker 进程**。

在应用根目录用一次性检查确认已部署形态：

```bash
bash bin/console router:list --json
bash bin/console router:prefix
bash bin/console schedule:list
```

若应用有周期性任务，且希望在真实定时执行开始前做一次直接证明，用 `schedule:list` 中的派生名称显式运行一条任务：

```bash
bash bin/console schedule:task <name>
```

仅在应用实际使用时才启动长生命周期进程：

```bash
bash bin/console rpc:daemon
```

只使用与应用形态匹配的命令：

- 暴露应用前务必检查路由表
- 当部署的公开 URL 形态可能与本地假设不同时，使用 `router:prefix`
- 仅当应用使用周期性任务时运行 schedule 相关命令
- 用 `schedule:task <name>` 做最快直接证明；`schedule:run` 留给当前已到期的任务
- 仅当应用使用 RPC worker 时启动 `rpc:daemon`

若项目没有 `bin/console`，使用 [CLI 快速开始](../cli/README.md#fallback-entry) 中的备用入口形态。
对队列 worker，遵循同一原则：作为独立运维进程运行所选消费循环，不要假定 HTTP 运行时会启动它。

首次成功的标准很小：

- 一份配置面描述应用
- 一种 HTTP 运行时刻意服务请求
- 一条持久路径故事与文件生命周期匹配
- 一条 worker 进程故事与应用实际使用的功能匹配

## 初期应避免什么 {#pitfalls}

有意让首次生产部署保持狭窄。

过早避免：

- **把 PHP 内置服务器发到生产** — `type: php` 仅用于开发与快速本地测试
- **把 `SIGUSR1` 当作完整发布重载** — 配置、`.env` 与 vendor 变更仍需要完整重启
- **把持久上传放在 `@runtime` 下** — 该路径匹配运行时生命周期，而非长期文件归属
- **假定 HTTP 会自动启动 Schedule、Queue 或 RPC 循环** — 每条运行时通道仍需要各自的运维进程形态
- **把 `schedule:run` 当作忽略表达式时间** — 直接证明用 `schedule:task <name>`，`schedule:run` 留给到期任务
- **在生产保留开发式调试** — 保持 `debug: false`，仅在显式受控时使用 Inspector
- **在边界尚未真实之前拆分所有 Redis 工作负载** — 先有一条稳定的客户端故事，流量模式确有需要后再加命名客户端
- **在单台主机或单条部署路径可信之前设计完整平台** — 先证明一条可读的生产拓扑

完成首次生产部署路径后，初学者应能感到：

> 我知道在 Switon 应用于生产安全运行之前，哪些配置、运行时、路径与 worker 进程边界必须显式化。

## 延伸阅读 {#go-deeper}

- **在上线细节重要之前完成第一条 JSON 请求路径：** [构建 API](build-an-api.md#build-an-api)
- **在上线细节重要之前完成第一条控制器到模板的页面路径：** [构建 HTML 页面](build-html-pages.md#build-html-pages)
- **深入了解 env 加载、配置合并规则与应用元数据：** [Kernel](../kernel/README.md) · [Core 包](../core/README.md#app-metadata-vs-scene)
- **详细了解反向代理、适配器调优与重启策略：** [HTTP](../http/README.md) · [服务器与部署](../http/server.md)
- **让周期性工作走独立的生产调度路径：** [添加周期性任务](add-recurring-work.md#add-recurring-work) · [Schedule](../schedule/README.md)
- **运维持久化后台 worker 与传输边界：** [添加持久化后台工作](add-durable-background-work.md#add-durable-background-work) · [Queue](../queue/README.md)
- **将请求/回复 worker 作为独立守护进程路径运行：** [添加基于队列的请求/回复](add-queue-backed-request-reply.md#add-queue-backed-request-reply) · [RPC](../rpc/README.md)
- **让上传与生成文件落在正确的生命周期路径：** [Storage](../storage/README.md)
- **调优共享客户端、连接池与队列专用 Redis 流量：** [Redis](../redis/README.md)
- **选择更安全的生产日志基线与受控调试姿态：** [Log](../log/README.md) · [Inspector](../inspector/README.md)
