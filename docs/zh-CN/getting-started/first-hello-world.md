# 首次 Hello World 清单 {#first-hello-world}

在教程或任务指南之前，快速证明全新的 Switon 应用**真的在跑**。Composer 源可用时用 `switon/skeleton`。

## 最短可运行路径 {#quick-path}

前提：`php`、`composer`、`git` 已可用。否则先 [环境准备](environment.md#environment)。

### 1. 创建应用 {#step-create}

```bash
composer create-project switon/skeleton my-app
cd my-app
```

期望：项目目录存在；根目录有 `bin/console`、`public/index.php`、`app/`、`switon.yml`（可选 `config/` 目录用于 PHP-only 补丁）。

### 2. 证明 CLI {#step-cli}

```bash
bash bin/console list
```

期望：命令列表正常输出。

### 3. 证明路由 {#step-router}

```bash
bash bin/console router:list
bash bin/console router:prefix
```

期望：能看到路由表；`router:prefix` 打印当前 public 前缀（已发布 skeleton 默认常为 `/api`）。

### 4. 启动 HTTP {#step-http}

```bash
SERVER_TYPE=php php public/index.php
```

期望：本地服务启动（默认常是 `http://127.0.0.1:9501`），进程保持运行。

### 5. 证明 HTTP 响应 {#step-http-check}

```bash
curl http://127.0.0.1:9501/api/
curl http://127.0.0.1:9501/api/time/current
```

期望：欢迎 payload 与带前缀的控制器路由均正常。

### 6. 到此为止 {#step-stop}

以上通过即已有有效的 Switon「Hello World」基线。**不必**为此装数据库、认证、缓存或 HTML 模板。在浏览器里猜 URL 之前，先确认 CLI 与 `router:prefix`。
