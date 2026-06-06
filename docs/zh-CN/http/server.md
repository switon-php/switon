---
sidebar_label: 服务器与部署
description: FPM、PHP 内置服务器与 Swoole 的 HTTP 运行时与服务器选项。
---

# 服务器与部署 {#server-deployment}

为 FPM、PHP 内置服务器或 Swoole 选择 HTTP 运行时与服务器选项。

**关键特性：**

- **运行时选择** — `Switon\Http\ServerOptions::type` 可选 `auto`、`php`、`fpm`、`swoole`
- **启动入口** — `Switon\Http\Kernel::start()` 启动应用并启动 HTTP 服务器
- **适配器设置** — 主机、端口及适配器专有项在 `Switon\Http\ServerOptions` 上配置
- **服务器事件** — 生命周期钩子覆盖启动、关闭与工作进程事件

## 快速开始 {#quick-start}

在 `switon.yml` 中配置服务器选项。

```yaml
# switon.yml
Switon\Http\ServerOptions:
  type: auto
  host: 0.0.0.0
  port: 9501
```

`type: auto` 为默认。仅在需要强制某一运行时再用 `php`、`fpm` 或 `swoole`。

## 核心概念 {#core-concepts}

### 运行时选择 {#runtime-selection}

`Switon\Http\ServerOptions` 控制服务器适配器与监听地址。

- `host` — 监听主机
- `port` — 监听端口
- `type` — 运行时适配器
- `settings` — 透传适配器专有选项

### 启动 {#startup-and-configuration}

`Switon\Http\Kernel::start()` 先启动应用，再启动服务器。

### 服务器事件 {#server-events}

需要启动、关闭或工作进程生命周期钩子时使用服务器事件。

- `Switon\Http\Server\Event\ServerReady`
- `Switon\Http\Server\Event\ServerStart`
- `Switon\Http\Server\Event\ServerStarted`
- `Switon\Http\Server\Event\ServerWorkerStart`
- `Switon\Http\Server\Event\ServerShutdown`

### Nginx {#nginx}

Swoole 位于 Web 服务器边界之后时，用 Nginx 作反向代理。

## 注意 {#keep-in-mind}

- `type: auto` 是最稳妥的初选项。
- 将运行时专有调优放在 `Switon\Http\ServerOptions.settings`。
- 正常请求处理放在 `RequestHandler`，不要写在服务器启动代码里。

## 下一步 {#next}

- **选择响应行为：** [响应](response.md)
- **读取请求输入：** [请求](request.md)
- **查看当前选项：** [FAQ](faq.md)
