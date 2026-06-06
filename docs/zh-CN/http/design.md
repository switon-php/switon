---
sidebar_label: HTTP 设计说明
description: 为何统一控制器 HTTP 边界，以及 Request/Response、事件与运行时选型。
---

# 设计说明 {#design-rationale}

`switon/http` 先**标准化应用层的 HTTP 边界**，再把运行时细节暴露出来。

## 参见 {#see-also}

- [HTTP 包](README.md#http-package) — 主指南与配置
- [FAQ](faq.md#http-faq) — 常见问题与排错
- [Context 包](../context/README.md#context-package) — 长生命周期服务的可变作用域状态（与请求属性对照）
- [DI 包](../di/README.md#di-package) — 控制器与监听器依赖的自动装配
- [Router 包](../router/README.md#router-package) — 控制器上使用的路由映射属性

---

## 核心问题 {#core-problem}

如果每个应用都自己决定路由、输入处理、响应塑形和横切钩子，HTTP 结构很快就会漂移：

- 控制器代码把传输解析与业务逻辑混在一起
- 请求级横切以不同风格实现
- 运行时假设渗入普通应用代码

## 主要目标 {#primary-goal}

包在应用与运行时之间保持稳定的 HTTP 形状：

- **收益：** controller action 更易读，也更易复制
- **收益：** 请求级行为可以集中管理
- **代价：** 一些底层运行时技巧不在默认路径里

## 为何采用这一形状 {#why-this-shape}

- 控制器拥有路由声明与 action 入口
- `Switon\Http\RequestInterface` 拥有请求读取
- `Switon\Http\ResponseInterface` 拥有响应写入
- 生命周期事件拥有请求级钩子
- `Switon\Http\ServerOptions` 拥有运行时选择

框架边界清晰可见，同时也不会逼每个应用去采纳庞大的内部模型。

## 类型化输入与响应边界 {#typed-input-and-response-boundaries}

类型化输入类把重复的请求解析移出 action。

- `#[Switon\Http\Attribute\RequestBody]` 显式限定正文输入
- `#[Switon\Http\Attribute\RequestQuery]` 显式限定查询输入
- `#[Switon\Http\Attribute\RequestData]` 显式限定合并输入

响应塑形与之分开：常见 JSON 路径直接返回数组；只有在 HTTP 形状确实重要时才用 `ResponseInterface`。
