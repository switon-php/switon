---
sidebar_label: 模块与 Area
description: 应用结构超出扁平 app/ 树时，如何稳定放置功能代码、area 与可复用 module。
---

# 理解模块与 Area {#modules}

当应用结构已超出单一扁平 `app/` 树，需要稳定方式放置功能代码时，读这一页。

## 术语速览 {#terms}

- **module** — 有连贯目的的功能或可复用单元；可应用内本地，也可归 package/plugin
- **area** — 应用内按功能分组，通常在 `app/Areas/<Area>/`
- **entrypoint** — 由 runtime 包发现的 controller、command、listener、task 或 job 类
- **service boundary** — 在入口之后拥有可复用业务行为的类或契约

## 应用中的 Area {#areas-in-applications}

当一项功能文件足够多、按功能分组比仅按类类型分组更清晰时，使用 area。

常见形状：

```text
app/
  Areas/
    Billing/
      Controller/
      Service/
      Entity/
      Repository/
      Command/
      Job/
      Task/
      Listener/
```

这仍是应用代码。area 名帮助人与发现规则找到相关入口，但不会创建独立 runtime 容器或独立应用。

多个包的默认已理解 area 路径，例如 controller、listener、command、job、task 的发现模式：`@app/Areas/*/Controller/*Controller.php`、`@app/Areas/*/Job/*Job.php` 等。

## 超出单一应用的 Module {#modules-beyond-app}

用 module 指更宽的可复用功能边界。

module 可以是：

- 不打算在应用外复用的应用内 area
- 通过包自有接线注册框架元数据的 Composer 包
- 类似插件的功能，贡献路由、command、listener 等入口，而不改应用全局文件 glob

实用规则看所有权：

- 功能只属于一个应用 → 从 area 开始
- 功能应跨应用版本化安装 → 走向 package/module 边界
- 功能只增加一种 runtime 入口 → 避免过早做成 module 形状

## 放置规则 {#placement-rules}

入口靠近发现它的 runtime 包：

- HTTP controller → `Controller/`
- CLI command → `Command/`
- 事件 listener → `Listener/`
- 队列 job → `Job/`
- 定时 task → `Task/`

可复用业务行为放在 service 契约下。controller、command、job、task 在做相同业务工作时，通常应调用同一 service。

不要用 area 掩盖跨功能耦合。若两个 area 需要相同行为，抽出 service 契约，或将共享行为移到真正拥有的功能。
