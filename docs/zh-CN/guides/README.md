---
description: 面向具体任务的 Switon 指南：构建 API、HTML 页面、CLI 命令、周期性工作与生产部署。
---

# 指南 {#guides}

当任务已经明确、你想直接按任务组装 Switon 各包，而不是按组件顺序通读包文档时，就看这些任务优先的指南。

## 从这里开始 {#start-here}

如果本地运行环境还没准备好，先从 [安装 Switon](../getting-started/install.md#install) 开始。若 shell 里还缺少 `php`、`composer` 或 `git`，先看 [环境准备](../getting-started/environment.md#environment)。

当任务已经明确、你希望走最短路径拿到可用结果时，就用指南；需要包级权威契约时，查阅各组件 `README.md`。如果不确定从哪里开始，可以按下面选：

- [构建 API](build-an-api.md#build-an-api) — 最短的任务优先起点
- [教程](../tutorial/README.md#tutorial) — 更慢的有序路径
- [核心概念](../concepts/README.md#switon-fundamentals) — 先理解框架方法论与心智模型

在决定代码应放在何处时阅读概念页；在准备构建一条可用路径时使用指南。
不必先通读整个索引。
选定一项任务并直接开始。

若 `boundary`、`runtime`、`Context`、`worker` 这类词反复出现、影响阅读，可以先对照 [初学者与译者术语表](../concepts/glossary.md)；然后直接在 [可用指南](#available-guides) 里跳到对应任务。

## 可用指南 {#available-guides}

把下面这份列表理解成“当这是应用里的下一项真实工作时从这里开始”，不是包目录。

- [构建 API](build-an-api.md) — [Kernel](../kernel/README.md)、[HTTP](../http/README.md)、[Router](../router/README.md)、[Invoker](../invoker/README.md)、[Validation](../validation/README.md) 和 [DI](../di/README.md)：在持久化数据、认证、缓存或后台工作之前，先打通第一条 JSON 路径。
- [构建 HTML 页面](build-html-pages.md) — [Kernel](../kernel/README.md)、[HTTP](../http/README.md)、[Controller](../http/controller.md)、[Router](../router/README.md)、[View](../view/README.md)、[Renderer](../renderer/README.md) 和 [DI](../di/README.md)：在认证、页面缓存或更广泛的前端工作之前，先打通第一条 HTML 页面路径。
- [添加国际化](add-internationalization.md) — [I18n](../i18n/README.md)、[HTTP](../http/README.md)、[Cookies](../http/cookies.md) 和 [Context](../context/README.md)：当一条可用的页面或 API 路径需要译文、区域设置或保存的语言偏好时。
- [添加横切 HTTP 行为](add-cross-cutting-http-behavior.md) — [HTTP](../http/README.md)、[Controller](../http/controller.md)、[Response](../http/response.md) 和 [请求生命周期](../concepts/request-lifecycle.md)：当一条请求路径需要请求级钩子、只包裹 action 的逻辑，或统一的错误到响应规则时。
- [调用上游 API](call-an-upstream-api.md) — [HTTP Client](../http-client/README.md)、[DI](../di/README.md)、[Factory](../di/factory.md) 和 [Log](../log/README.md)：当一条业务路径需要调用第三方 API 或另一个内部服务时。
- [持久化数据](persist-data.md) — [ORM](../orm/README.md)、[DB](../db/README.md) 和 [Query](../query/README.md)：当应用需要保存数据，并且需要决定读写该放在哪里时。
- [为已有应用添加测试](add-testing-to-a-working-app.md) — [Testing](../testing/README.md)、[Testing Database Code](../db/testing.md) 和 [HTTP](../http/README.md)：当一条 API、CLI 或已有数据路径已经能工作，但还需要一小套可靠测试时。
- [处理文件上传与下载](handle-file-uploads-and-downloads.md) — [Storage](../storage/README.md)、[Request](../http/request.md)、[Response](../http/response.md) 和 [Validation](../validation/README.md)：当一条 HTTP 路径需要用户文件、安全的文件键或由应用提供的下载时。
- [添加 CLI](add-cli.md) — [CLI](../cli/README.md)、[DI](../di/README.md) 和 [Log](../log/README.md)：当现有服务在周期性工作或更复杂的运行流之前，需要先有一条可手动执行的命令时。
- [添加周期性任务](add-recurring-work.md#add-recurring-work) — [Schedule](../schedule/README.md)、[CLI](../cli/README.md) 和 [Log](../log/README.md)：当现有服务或清理/报表路径应按定时执行，且还没进入基于队列的工作或更复杂运行流时。
- [添加持久化后台工作](add-durable-background-work.md) — [Queue](../queue/README.md)、[Executor](../executor/README.md) 和 [Redis](../redis/README.md)：当一条已验证的服务、命令或周期任务应该把工作交给队列，而不是继续放在回复链路或 worker 平台设计里时。
- [发送事务性邮件](send-transactional-email.md) — [Mail](../mail/README.md)、[Queue](../queue/README.md) 和 [Schedule](../schedule/README.md)：当一项已经工作的业务事件需要欢迎、重置、回执或审批类邮件时。
- [添加基于队列的请求/回复](add-queue-backed-request-reply.md) — [RPC](../rpc/README.md)、[Queue](../queue/README.md) 和 [Executor](../executor/README.md)：当一条已验证的服务或持久化 worker 路径需要先等一次回复，再去讨论更广的协议设计时。
- [添加缓存](add-caching.md) — [Cache](../cache/README.md)、[HTTP Cache](../http-cache/README.md) 和 [Redis](../redis/README.md)：当重复读取需要安全、有效的一步性能优化时。
- [添加认证](add-authentication.md) — [HTTP](../http/README.md)、[Session](../session/README.md)、[JWT](../jwt/README.md) 和 [Authz](../authz/README.md)：当一条可用的 HTTP 路径需要登录态、Bearer 令牌或受保护端点时。
- [添加授权](add-authorization.md) — [Authz](../authz/README.md)、[HTTP](../http/README.md)、[Controller](../http/controller.md) 和 [Core](../core/README.md)：当认证已经可用，且需要正确放置角色、权限码、服务检查或所有权规则时。
- [用速率限制保护端点](protect-endpoints-with-rate-limits.md) — [Throttle](../throttle/README.md)、[HTTP](../http/README.md) 和 [Authz](../authz/README.md)：当登录、重置或公开路由需要防滥用保护时。
- [添加 API 文档](add-api-docs.md) — [OpenAPI](../openapi/README.md)、[HTTP](../http/README.md)、[Session](../session/README.md)、[JWT](../jwt/README.md) 和 [Authz](../authz/README.md)：当路由与认证形态已经稳定，客户端需要一份已文档化的契约时。
- [将应用部署到生产环境](deploy-an-app-to-production.md) — [Kernel](../kernel/README.md)、[HTTP](../http/README.md)、[Storage](../storage/README.md)、[Redis](../redis/README.md) 和 [Log](../log/README.md)：当应用已经能工作，需要在运行时、环境/配置、持久化文件，以及独立的调度器或 worker 进程之间形成稳定上线形态时。
- [验证常量约束](validation-constant-constraints.md) — [Validation](../validation/README.md)：当你需要借助 `ConstantValue` 做基于类常量的验证时。

## 下一步 {#next}

这张指南地图是给任务优先的组装方式用的。需要更慢的学习路径或包级深度时，再跳到对应章节。
- **现在就从一个具体任务开始：** [构建 API](build-an-api.md#build-an-api) · [构建 HTML 页面](build-html-pages.md#build-html-pages)
- **先学框架模型：** [核心概念](../concepts/README.md#switon-fundamentals)
- **走更慢的有序路径：** [教程](../tutorial/README.md#tutorial)
- **按所属包跳转：** [Kernel](../kernel/README.md) · [HTTP](../http/README.md) · [ORM](../orm/README.md) · [CLI](../cli/README.md) · [Queue](../queue/README.md) · [Cache](../cache/README.md)
