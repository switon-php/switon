---
sidebar_label: DI 设计说明
description: 为何 interface-first、属性注入、Factory 槽位，以及容器不存请求态。
---

# 设计说明 {#design-rationale}

DI 这一层让应用代码围绕**依赖**组织，而不是围绕“怎么从容器里取东西”。

## 参见 {#see-also}

- [DI 包](README.md) — 接线、自动装配与容器边界
- [FAQ](faq.md) — 常见接线问题
- [Autowired](autowired.md) — 属性注入细则
- [Factory](factory.md) — 命名实例与槽位

---

## 统一的依赖形态 {#shared-dependency-shape}

各模块如果各自发明依赖写法，结构最先走样。DI 让 Switon 应用保持一致：类型化属性、契约优先解析，以及共享服务和新建对象的边界都清楚。

## 接口优先 {#interface-first}

同命名空间 `XxxInterface` → `Xxx` 是默认路径，常见情况免注册；只有真正需要时才写显式绑定。

## 属性注入优先 {#property-injection-first}

`#[Autowired]` 把依赖声明贴在使用的类上，并在构造函数之前完成注入。路径是短了，但也意味着 **`new` 不是注入路径**。

## 命名槽，而不是业务里的命名 ID {#named-slots}

一个契约多实例时，优先**属性名槽位 + Factory 配置**；只有名称来自数据时才用 `NamedLookupInterface`。

## 容器状态是进程级 {#container-state}

`get()` 返回共享实例，容器**不是**请求作用域状态仓。可变请求/连接数据属于 Context，不属于 DI 单例字段。

## 引导顺序在更上层 {#bootstrap-belongs-to-the-framework}

DI 提供 `ContainerInterface`、`InjectorInterface`、`ServiceProvider`，但**启动编排**在更上层框架里，DI 只专注接线规则，不负责启动顺序。
