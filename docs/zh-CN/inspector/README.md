---
sidebar_label: Inspector 包
description: switon/inspector：开发态请求诊断、X-Inspector-Link、收集器与 Redis/文件存储。
---

# Inspector 包 {#inspector-package}

`switon/inspector` 提供**请求级**检查边界：每请求一条链接、一份存储载荷，同一数据可渲染为 HTML / JSON / 文本 / 原始 JSON。

> **默认仅开发使用。** Inspector 会暴露配置快照、已解析服务、请求数据与收集器输出；在 `dev` 与 `test` 会自动启用；若要在其它环境开启，须先接受暴露风险。

**关键特性：**

- **每请求一链：** 响应头 `X-Inspector-Link` 指向已存储载荷
- **收集器组合：** 内置、各包与应用注册的收集器合并为一份 payload
- **四种视图：** 同一载荷可渲染为 `html`、`json`、`txt`、`raw`
- **按 TTL 选后端：** `ttl > 0` 写入 Redis（`base64(gzip(json))`）；`ttl = 0` 将 gzip JSON 写到 `@runtime/inspector/{key}.zip`
- **工具钩子：** 每次生成链接会触发 `Switon\Inspector\Event\InspectorLinkGenerated`；`bash bin/console inspector:watch` 可 tail Redis pub/sub 广播

---

## 安装 {#installation}

**环境要求：** PHP 8.3+、`switon/http`、`switon/redis`、`switon/kernel`、`switon/log`、`switon/router`、`switon/event`、`switon/di`

```bash
composer require --dev switon/inspector
```

---

## 快速开始 {#quick-start}

在 `dev` 或 `test` 环境发一次请求，打开返回的 Inspector URL。

```bash
curl -i http://<host>:<port>/api/users
```

成功响应通常含：

```text
X-Inspector-Link: http://<host>:<port>/?__inspector=<key>.html
```

`dev` 下还可能看到：

```text
X-Inspector-Route: <Controller>::<action>
```

`X-Inspector-Route` 默认仍是 **dev-only** 响应头，除非将 `Switon\Inspector\Listener\RouteHeaderListener.enabled` 设为 `true`。

无 Redis 时，请先将 `Switon\Inspector\StorageInterface.ttl` 设为 **`0`**，载荷会存到 `@runtime/inspector/{key}.zip`（gzip 压缩的 JSON）。

浏览器打开 `.html`，或直接拉其它视图：

```bash
curl "http://<host>:<port>/?__inspector=<key>.json"
curl "http://<host>:<port>/?__inspector=<key>.txt"
curl "http://<host>:<port>/?__inspector=<key>.raw"
```

---

## 核心概念 {#core-concepts}

### 请求生命周期 {#request-lifecycle}

Inspector 挂在请求生命周期上，而非单独 debug 端点。

- `RequestBegin` — 生成 key、设置 `X-Inspector-Link`；
- 请求期间 — 收集器观察框架事件；
- `RequestEnd` — 写入一份存储载荷；
- `RenderListener` — 以 `html` / `json` / `txt` / `raw` 提供查看。

`tail` 开启且响应内容为数组时，会在 `inspector` 字段附带当前链接。ApacheBench 请求会跳过。

### 收集器组合 {#collector-composition}

来源：

- 包元数据 `extra.switon.collectors`；
- 应用配置 `CollectorDiscoveryInterface.collectors`。

将某 key 设为 **`null`** 可禁用。各包可贡献 Tab，无需写进核心包。

### 存储 {#storage}

`Switon\Inspector\StorageInterface.ttl` 选择后端。`ttl > 0` 时条目写入 Redis，键为 `prefix + key`，值为 `base64(gzip(json))`，并施加 TTL；`ttl = 0` 时写入 `@runtime/inspector/{key}.zip`，内容为 gzip 压缩的 JSON。**两种后端之间不会自动回退。**

仅 Redis 模式下 `broadcast = true` 时，会在频道 `__inspector:{app_id}:{ip}:{handler}` 发布生成的链接；`inspector:watch` 订阅同一模式。

### 输出 {#output}

同一份载荷可渲染为 `html`、`json`、`txt`、`raw`。

### 事件 {#events}

- `Switon\Inspector\Event\InspectorLinkGenerated` 在每次生成链接时触发，携带 URL 与存储 key；自动化已建在事件体系上时用它。
- `bash bin/console inspector:watch` 订阅 `__inspector:{app_id}:{ip}:{handler}` 并打印匹配链接。可用 `--handlerPrefix`、`--ip` 收窄（`--ip` 无点号时按最后一字节匹配）；**仅**在 Redis 模式下可用。

---

## 配置 {#configuration}

默认即可；仅在需要控制启用、保留、收集器或渲染时再改。

- `Switon\Inspector\InspectorInterface.enabled`（`null`）在 `dev` 与 `test` 自动启用；`false` 时 `Inspector::boot()` 不工作且不注册监听器。
- `Switon\Inspector\InspectorInterface.tail`（`true`）在响应序列化时把链接写入数组正文的 `inspector` 字段。
- `Switon\Inspector\Listener\RouteHeaderListener.enabled`（`null`）在 `dev` 自动启用 `X-Inspector-Route` 响应头。

```yaml
# switon.yml
Switon\Inspector\InspectorInterface:
  enabled: null
  tail: true

Switon\Inspector\StorageInterface:
  ttl: 3600
  prefix: cache:inspector:
  broadcast: true

Switon\Inspector\CollectorDiscoveryInterface:
  autoDiscover: true
  collectors:
    # logger: null
    # custom: App\Inspector\RequestTagCollector

Switon\Inspector\HtmlRendererInterface:
  # pageTemplate: '@switon.inspector.resources/templates/inspector.html'
  tabs:
    # custom: '@switon.inspector.resources/templates/<collector>-tab.js'

Switon\Inspector\Listener\RouteHeaderListener:
  enabled: null
```

### 收集器 {#collector-settings}

- `autoDiscover`（`true`）读取已安装包的 `extra.switon.collectors`
- `collectors` 增加、覆盖或禁用单个收集器 key（`null` 禁用）

### 存储 {#storage-settings}

- `ttl` 选择后端（`>0` Redis，`=0` 文件系统）；默认 Redis 路径需要 `switon/redis` 可用
- `prefix` 设置 Redis 键前缀
- `broadcast` 发布链接到 Redis 频道；文件系统模式下忽略

### 运行时开关 {#runtime-switches}

- `enabled` 控制 Inspector 是否整体启动
- `tail` 开启且响应为数组时，把当前链接附在响应里
- `Switon\Inspector\Listener\RouteHeaderListener.enabled` 控制 `X-Inspector-Route`
- 可在 `Inspector` 子类中重写 `onServerReady()` 做启动探测或诊断

### 渲染 {#rendering-settings}

- `pageTemplate` 替换默认 HTML 壳
- `tabs` 将收集器 key 映射到各 Tab 的 JS 模板

---

## 选项发现 {#option-discovery}

用 `config:options` 查看当前安装版本的选项面。

```bash
bash bin/console config:options 'Switon\Inspector\InspectorInterface'
```

需要存储、收集器或 HTML 渲染的当前选项面时，分别查询 `StorageInterface`、`CollectorDiscoveryInterface`、`HtmlRendererInterface`。

---

## 使用注意 {#keep-in-mind}

- 默认面向开发；不要把 Inspector 输出当成默认可上的生产遥测。
- `ttl > 0` 即 Redis，`ttl = 0` 即文件系统；**不会**自动回退。
- ApacheBench 请求会整段跳过 Inspector（无链接、无存储载荷）。
- 在 `collectors` 里把已发现的 key 设为 `null` 可禁用，无需 fork 提供方包。
- `InspectorLinkGenerated` 只带 URL 与存储 key，**不带**完整载荷。

---

## 下一步 {#next}

- **常见问题：** [FAQ](faq.md#inspector-faq)
- **设计取舍：** [设计说明](design.md#design-rationale)
