---
sidebar_label: Inspector 设计说明
description: 单链接、单载荷、元数据收集器；开发优先的非生产遥测。
---

# 设计说明 {#design-rationale}

`switon/inspector` 把请求诊断收成**一条请求作用域表面**，避免各包各自发明 debug 通道。

## 参见 {#see-also}

- [Inspector 包](README.md#inspector-package) — 用法、配置与运行决策
- [FAQ](faq.md#inspector-faq) — 短答与排错

---

## 要解决的问题 {#core-problem}

请求诊断容易漂成多条临时路径：自建 debug URL、硬编码头、共享服务上挂状态、Tab 无法组合——全应用检查体验不一致。

## 首要目标 {#primary-goal}

标准化：**一条链接、一份存储载荷、一条渲染路径**。

- **好处：** 跨包共享检查数据容易；
- **好处：** 同一数据服务浏览器、终端与工具链；
- **代价：** 是受控调试面，不是通用可观测平台。

## 核心选型 {#core-choices}

- **生命周期生成链接：** 写 payload 前由请求事件创建链接；
- **元数据驱动收集器：** 包通过元数据贡献面板，应用可覆盖或 `null` 禁用；
- **存储与渲染分离：** 存什么与怎么看解耦；
- **上下文作用域状态：** 收集器可变数据放在 context，Inspector 本身仍请求级。

## 取舍 {#trade-offs}

- `ttl > 0` → Redis；`ttl = 0` → 文件系统；
- 并非每个已安装包都有 Tab；
- 默认开发优先，生产暴露须显式决定。
