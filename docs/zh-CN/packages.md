---
description: 按职责浏览 Switon 各包：简短说明与直达 owner 文档的链接。
---

# 包索引 {#packages}

## 核心运行时 {#core-runtime}

- [Core](core/README.md) — 框架其余部分共用的契约、属性与小工具
- [Principal](principal/README.md) — 将当前身份与租户规范为运行时状态
- [Kernel](kernel/README.md) — 用一条引导路径启动 HTTP、CLI、Worker 与自定义运行时
- [DI](di/README.md) — 属性注入、自动装配与命名槽位
- [Context](context/README.md) — 可变状态放在请求/协程本地，而非共享服务上
- [Event](event/README.md) — 基于属性的进程内事件与监听器
- [Invocation](invocation/README.md) — 方法级守卫与拦截器契约
- [Invoker](invoker/README.md) — 调用已知实例方法而无需手工拼每个参数
- [ID](id/README.md) — 按排序与存储需求选择 ID 生成器
- [Pool](pool/README.md) — 可借还可的共享资源访问
- [Sync](sync/README.md) — 进程内锁、等待等协调原语
- [Composer Extra](composer-extra/README.md) — 运行时读取已安装包的 `extra` 元数据

## Web 与交付 {#web-and-delivery}

- [HTTP](http/README.md) — 在统一请求/响应模型上构建控制器式端点
- [Router](router/README.md) — 可预测路径组合的属性式 HTTP 路由
- [View](view/README.md) — 用 `Controller::Action` 模板解析渲染 HTML
- [Renderer](renderer/README.md) — 页面、片段、邮件与 UI 片段的模板渲染
- [Session](session/README.md) — HTTP 请求间的小块用户态状态
- [Authorization](authz/README.md) — 访问规则贴近控制器与服务
- [Dict](dict/README.md) — 在 action 参数、校验与 UI 选项间统一受控值集
- [JWT](jwt/README.md) — 签发与校验 HMAC JWT
- [Throttle](throttle/README.md) — 对 HTTP action 与自定义负载做 Redis 限流
- [HTTP Cache](http-cache/README.md) — 用 `ETag` 与缓存头缓存完整控制器响应
- [I18n](i18n/README.md) — 翻译、区域回退与请求级 locale
- [OpenAPI](openapi/README.md) — API 文档贴近控制器路由
- [Captcha](captcha/README.md) — 为 API 与 SPA 生成并校验无会话 CAPTCHA

## 数据与存储 {#data-and-storage}

- [DB](db/README.md) — 直接 SQL，安全默认与显式事务钉住
- [Query](query/README.md) — 运行时组装过滤、排序与分页
- [ORM](orm/README.md) — 类型化实体与仓储主导的应用代码
- [ORM Codegen](orm-codegen/README.md) — 从现有表脚手架 ORM 代码
- [Sharding](sharding/README.md) — 分库分表同时尽量保持仓储/实体代码平常
- [Cache](cache/README.md) — Redis 缓存与读穿默认
- [Redis](redis/README.md) — 连接池、瞬态连接与生命周期事件
- [Storage](storage/README.md) — 上传、导出、生成文件等本地 blob
- [Crypto](crypto/README.md) — 对称加密、密钥与轮换

## 执行与集成 {#execution-and-integration}

- [CLI](cli/README.md) — 用普通 PHP 类写应用命令
- [Schedule](schedule/README.md) — 在 `Switon` 内跑周期任务，少接一堆 cron 入口
- [Queue](queue/README.md) — 生产与 Worker 之间的 Redis 持久作业缓冲
- [Executor](executor/README.md) — 从应用不同位置触发同一套 runner 式工作
- [RPC](rpc/README.md) — 调用方与 Worker 已共享 PHP 类型时的队列请求/回复
- [HTTP Client](http-client/README.md) — 以 JSON 优先的默认路径调用上游 API
- [Mail](mail/README.md) — 用流式消息模型发 SMTP 邮件

## 质量与诊断 {#quality-and-diagnostics}

- [Validation](validation/README.md) — 属性规则、规范化与可本地化消息
- [Log](log/README.md) — PSR-3 日志、结构化上下文与零配置默认
- [Inspector](inspector/README.md) — 在运行中的 `Switon` 应用里检查请求
- [Testing](testing/README.md) — 在贴近真实的 DI 容器上跑包测试
- [Beacon](beacon/README.md) — 通过一个 `Switon` 包安装面向应用的 AI 规则与技能
