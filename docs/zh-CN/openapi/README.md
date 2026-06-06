---
sidebar_label: OpenAPI 包
description: switon/openapi：拆分 YAML、open-api:sync/export/lint 与 handler-id 形 operationId。
---

# OpenAPI 包 {#openapi-package}

`switon/openapi` 通过**拆分 YAML 工作流**、从已发现路由**保守同步**、合并导出与质量 lint，让 API 文档与控制器路由保持一致。适用于希望规格与代码同源、又不必重写手写 YAML 的场景；包**不负责**客户端代码生成或外部捆绑。

**关键特性：**

- **拆分 YAML 工作流：** 顶层状态在 `openapi.yml`，共享 schema 在 `components/*.yml`，按控制器的操写在 `controllers/*.yml`。
- **路由驱动同步：** `open-api:sync` 仅追加缺失 stub，且仅针对带类级 `#[RequestMapping]` 的控制器。
- **稳定 `operationId`：** 生成的 id 遵循 Switon handler-id 形态，如 `user::index`。
- **合并导出：** `open-api:export` 将各片段合为单一文档。
- **Lint 支持：** `open-api:lint` 报告重复 id、断裂的 `#/...` 引用及常见规格质量问题。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+、`ext-json`、`switon/core`、`switon/command`、`switon/http`、`switon/router`、`switon/yaml`

```bash
composer require switon/openapi
```

---

## 快速开始 {#quick-start}

```bash
bash bin/console open-api:init
bash bin/console open-api:sync
bash bin/console open-api:export
bash bin/console open-api:lint
```

`open-api:init` 会在 `@root/openapi` 下脚手架 `openapi.yml`、`controllers/`、`components/` 与包 README。默认还会执行一轮 sync；仅需脚手架时用 `--no-sync`。

初始化后请直接编辑生成的 YAML。默认导出目标为 `{openapi-root}/dist/openapi.json`（通常为 `openapi/dist/openapi.json`）。

常见变体：

- `open-api:sync --controller=... --action=... --tail` 缩小单次同步范围；
- `open-api:export --out=... --json --deref --keep-components` 其它导出模式；
- `open-api:lint --strict` 使 CI 在仅出现警告时也失败。

---

## 核心概念 {#core-concepts}

### 拆分文件 {#split-files}

包读取**一棵树**，通常为 `openapi/`。**只**读取 `.yml` 文件。

| 路径 | 作用 |
|---|---|
| `openapi/openapi.yml` | 顶层文档状态，如 `openapi`、`info`、`servers` |
| `openapi/components/*.yml` | 共享 schema 与可复用组件 |
| `openapi/controllers/*.yml` | 按控制器的操作，由手写或 sync 扩展 |
| `openapi/dist/openapi.json` | 合并导出输出（默认） |
| `openapi/recommended.example.yml` | init 复制的脚手架示例，**不参与**合并 |
| `openapi/components/entity.example.yml` | init 复制的示例，删除前会参与合并 |

### `operationId` {#operation-id}

`operationId` 预期与 Switon handler-id 形态一致。

- `App\Controller\UserController::indexAction` → `user::index`
- `App\Areas\Admin\Controller\AccountController::captchaAction` → `admin.account::captcha`

这样路由文档、RBAC 类工具与 lint 预期保持一致。

### 同步范围 {#sync-scope}

`open-api:sync` 读取**控制器路由**，而非完整路由表。

- 仅扫描带类级 `#[RequestMapping]` 的控制器；
- 跳过 `#[ViewMapping]`；
- 跳过 HTTP 动词通配 `*`；
- 可根据路由模式与 action 返回类型追加缺失路径参数与 JSON 响应 schema stub；
- 控制器 YAML 文件名来自 `operationId` 中 `::` **之前**的前缀，**不是**控制器类名。

### 同步、导出与 Lint {#sync-export-lint}

- `open-api:sync` 发现控制器映射，**只**追加缺失 stub。
- `open-api:export` 合并 `openapi.yml`，再合并 `components/*.yml`，再合并 `controllers/*.yml`；各组按**文件名**排序。
- `--deref` **仅**解析合并文档内的本地 `#/...` 引用；除非指定 `--keep-components`，否则去掉顶层 `components`。
- `open-api:lint` 将重复 `operationId` 与断裂本地 `#/...` 引用报告为**错误**；偏弱结构为**警告**；`--strict` 使警告也导致失败。

---

## 使用注意 {#keep-in-mind}

- Sync **保守**；无法安全补丁的文件会被跳过。
- `--deref` **不**解析外部或非本地 `$ref`。
- 在替换或删除前，`components/entity.example.yml` 是**真实**输入文件。
- 若要在 PHP 中获得合并后的文档，可直接调用 `Switon\OpenApi\DocumentBuilderInterface::build()`。

---

## 下一步 {#next}

- **常见问题：** [FAQ](faq.md#openapi-faq)
- **设计取舍：** [设计说明](design.md#design-rationale)
- **对齐路由与控制器映射：** [HTTP 包](../http/README.md)
