---
sidebar_label: HTTP FAQ
description: HTTP：路由、输入、响应与运行时的常见问答与排错。
---

# HTTP FAQ {#http-faq}

面向安装和常见路由、输入、响应问题的短答。

> **前置：** [HTTP 包](README.md#http-package)

## 控制器 {#controllers}

### 为什么找不到路由？ {#route-not-found}

先执行 `bash bin/console router:list`。如果列表里没有这条路由，问题就在发现或映射，而不是请求处理。

### 如何让单个 action 保持精简？ {#keep-action-small}

优先用路由参数和标量 action 参数。
只有在需要头、文件或请求元数据时才引入 `Switon\Http\RequestInterface`。

## 输入 {#input}

### `RequestInterface::all()` 返回什么？ {#request-all}

返回合并后的输入：路由、POST、查询。同名冲突时**路由值优先**。

### 何时用类型化输入？ {#typed-input}

当同一请求形状需要复用并用类型化类校验时，使用 `#[Switon\Http\Attribute\RequestBody]`、`#[Switon\Http\Attribute\RequestQuery]` 或 `#[Switon\Http\Attribute\RequestData]`。

### 如何处理 `null`？ {#null-values}

`RequestInterface::get()` 将 `null` 视为缺失。若必须保留有效的 `null`，请用针对具体来源的读取方式。

## 响应 {#response}

### 何时返回数组？ {#return-array}

常规 JSON 成功路径返回数组即可。
只有在需要状态、头、重定向、下载或流式输出时才用 `Switon\Http\ResponseInterface`。

### 如何发送重定向？ {#redirect}

调用 `Switon\Http\Response::redirect()`。它会设置 `Location` 头，并**有意**终止当前流程。

## 运行时 {#runtime}

### 如何查看当前 HTTP 选项？ {#inspect-options}

执行 `bash bin/console config:options 'Switon\Http\ServerOptions'`。

### 同一套控制器代码能否在 FPM 与 Swoole 上运行？ {#fpm-and-swoole}

可以。包会按请求隔离状态，因此在支持的运行时上控制器代码保持一致。
