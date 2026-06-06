---
description: 在路由、请求/响应形态、认证边界与计划文档化的 429 行为稳定后，为客户端生成可维护的 OpenAPI 契约。
---

# 添加 API 文档 {#add-api-docs}

当一条可用的 HTTP 路径已有稳定路由、请求/响应形态、认证边界，以及你计划文档化的任何对客户端可见的 `429` 行为，而调用方现在需要一份持久 API 契约、又不想手工维护一整份巨大 spec 时，使用本指南。

## 选择第一种契约表面 {#contract-surface}

从已经稳定的、对客户端可见的边界起步。

| 表面 | 最适合 | 注意 |
|---|---|---|
| 登录端点 | 客户端需要第一条凭证到认证的契约 | 文档化成功是返回令牌、设置 Cookie，还是应用自有行为中两者兼有 |
| 当前用户端点 | UI 或 API 客户端需要一份权威身份快照 | 在优化之前保持响应形态紧凑且稳定 |
| 受保护业务端点 | 真实领域路由现在需要已认证或感知角色的访问 | 明确拒绝结果，让客户端能区分 `401` 与 `403` |
| 共享认证错误响应 | 多个受保护端点返回相同拒绝模式 | 在 `openapi/components/*.yml` 中定义一次共享响应，而不是到处重复 |

实用的首份契约常包括：

- 一个登录端点
- 一个当前用户端点
- 一个代表性的受保护端点
- 一个共享 `401` 响应与一个共享 `403` 响应

同时让文档化路径与真实公开路由形态对齐：

若应用有 `/api` 等全局路由前缀，OpenAPI 的 `paths:` 条目应包含该前缀，因为客户端调用的是公开 URL，而不是无前缀的控制器映射。
若公开前缀不清楚，在 sync 或编辑路由 YAML 之前用 `bash bin/console router:prefix` 确认。

## 推荐构建顺序 {#build-order}

### 1. 在路由与认证形态稳定之后再开始 {#build-stable-shape}

过早文档化不稳定路由会产生反复修改。
先证明真实 HTTP 路径、请求输入对象形态与认证选择已经可用。

### 2. 初始化 OpenAPI 树，再从控制器 sync {#build-init-sync}

按 [OpenAPI](../openapi/README.md) 的正常文件系统工作流起步。
一次性创建 `openapi/` 树，再让 `open-api:sync` 从控制器映射追加缺失的路由桩。

### 3. 在契约中明确认证传输 {#build-auth-transport}

选择与实际调用方边界匹配的措辞与 schema。
对基于 Cookie 的认证，把登录/当前用户流文档化为面向 session 的契约。
对 Bearer 令牌认证，文档化哪些端点签发令牌、哪些受保护端点期望携带它们。

### 4. 规范化共享拒绝响应与面向认证的 schema {#build-shared-responses}

当存在多个受保护路由时，先把首批共享响应与可复用 schema 放在顶层 `components:` 块；仅当 spec 更易维护时再拆到 `openapi/components/*.yml`。
这样 `401`、`403` 与当前用户 payload 在各 operation 间保持一致。

### 5. 交给他人之前先导出并 lint {#build-export-lint}

把 `open-api:export` 与 `open-api:lint` 当作第一条已文档化边界的终点。
目标不是「有一些 YAML」；目标是「合并后的契约足够连贯，客户端可以信任」。

## 最小工作流 {#minimal-workflow}

下面只用短示例展示路由发现、共享认证响应与受保护 operation 之间的接缝。
下文端点名与响应名仅为示例；稳定部分是 OpenAPI 目录布局与命令流。

### 初始化并从路由 sync {#workflow-init-sync}

从正常 CLI 流起步：

```bash
bash bin/console open-api:init @root/openapi
bash bin/console open-api:sync @root/openapi
bash bin/console open-api:export @root/openapi
bash bin/console open-api:lint @root/openapi --strict
```

首次 sync 之后，编辑生成的 YAML，不要把 sync 当作完整 YAML 编辑器。

### 添加共享认证响应 {#workflow-auth-responses}

把重复的拒绝结果放在一处共享位置：

```yaml
# openapi/openapi.yml
components:
  responses:
    auth.unauthorized:
      description: Caller is not authenticated.

    auth.forbidden:
      description: Caller is authenticated but not allowed to perform this action.
```

上述响应名是应用自有的示例名。请与 spec 其余部分保持命名一致。

### 丰富一个受保护 operation {#workflow-protected-operation}

再让一条受保护路由明确：

```yaml
# openapi/controllers/<prefix>.yml
paths:
  /me:
    get:
      operationId: <prefix>::me
      responses:
        '200':
          description: Current caller details.
        '401':
          $ref: '#/components/responses/auth.unauthorized'
        '403':
          $ref: '#/components/responses/auth.forbidden'
```

让 sync 拥有路由发现与初始 `operationId` 形态。
你的工作是丰富契约：稳定的响应含义、可复用 schema 与清晰的面向认证行为。
若应用在 `/api` 等全局前缀后暴露该路由，应文档化公开路径 `/api/me`。

### 文档化认证边界，而不只是成功路径 {#workflow-auth-boundary}

首份契约应明确客户端必须遵循的认证形态：

- 对 [Session](../session/README.md) 风格浏览器流，在 operation 文本与响应建模中描述基于 Cookie 的登录/当前用户期望
- 对 [JWT](../jwt/README.md) 风格 API 流，在需要它的受保护 operation 上描述 Bearer 令牌期望
- 对 [Authz](../authz/README.md) 保护的路由，让拒绝语义足够可读，使客户端知道应重新认证还是停止

## 首条路径上宜避免什么 {#pitfalls}

有意让第一条 API 文档路径保持窄。

下列内容不宜过早：

- **把 sync 当作完整 YAML 重写器** — `open-api:sync` 追加安全缺失桩；它不会接管整份 spec
- **发明与 handler id 漂移的 `operationId` 标签** — 保持与运行时对齐的形态，使文档、路由与感知权限的工具一致
- **在认证真实之前发布认证契约** — 先稳定登录/当前用户/受保护路由，再文档化
- **文档化每个内部角色或 service 细节** — 首份契约应聚焦对客户端可见的路由、payload 与拒绝结果
- **把第一条文档路径变成浏览器 UI 或 SDK 平台工作** — 在扩大工具之前先证明一份干净契约
- **在运行 sync 一次之前手工维护每条路由** — 先让感知路由的工作流创建基线

完成第一条 API 文档路径后，初学者应能感到：

> 我能向客户端展示如何认证、调用一条受保护端点，并理解 `401` 与 `403`，而无需反推控制器代码。

## 继续深入 {#go-deeper}

- **在首条请求路径之后衔接：** [构建 API](build-an-api.md#build-an-api) · [HTTP](../http/README.md)
- **在发布契约之前落定请求级钩子、action 包装或失败策略：** [添加横切 HTTP 行为](add-cross-cutting-http-behavior.md)
- **在身份与保护已经可用之后衔接：** [添加认证](add-authentication.md)
- **停留在分文件工作流、导出与 lint 表面：** [OpenAPI](../openapi/README.md)
- **在 sync 之前检查路由或解释 handler-id 形态：** [Router](../router/README.md)
- **让受保护 operation 与权限命名对齐：** [Authz](../authz/README.md)
- **精确文档化 Bearer 令牌调用方：** [JWT](../jwt/README.md)
- **文档化基于 Cookie 的登录与当前用户行为：** [Session](../session/README.md) · [HTTP cookies](../http/cookies.md)
- **在脚本或 CI 中自动化导出与 lint：** [CLI](../cli/README.md)
- **用稳定的运行时与 worker 进程形态发布已文档化的 API：** [将应用部署到生产环境](deploy-an-app-to-production.md#deploy-an-app-to-production)
