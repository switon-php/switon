---
sidebar_label: 授权设计说明
description: 授权包设计：声明式入口、身份态与业务权限分离、统一运行时路径。
---

# 设计说明 {#design-rationale}

`switon/authz` 把访问规则放在**离 HTTP 处理器最近**的地方写清楚；把「是否登录」与「业务权限码」分开；运行时一律按**精确权限码**判断。

## 参见 {#see-also}

- [授权包](README.md) — 用法、配置与注意点
- [FAQ](faq.md#authorization-faq) — 常见问题与排错

---

## 控制器优先 {#controller-first}

多数规则落在**拥有该行为的控制器类或方法**上，评审时最容易看见。

- **类级默认：** 写在控制器类上的 `#[Authorize]` 给出基线规则。
- **方法级覆盖：** 方法上的属性只替换**这一条方法**的默认，不影响类上其余动作。

## 用户态与角色 {#user-states-and-roles}

- **身份态：** `Authorize::ANONYMOUS` 与 `Authorize::AUTHENTICATED` 区分访客与已登录场景。
- **绕过键：** `Authorize::SUPERUSER` 是内置、可短路后续默认可授路径的角色键。
- **应用角色：** 业务自定义角色串由应用定义，经 `LookupInterface` 映到具体权限码。

这样「公开 / 登录」与「业务授权」不会糊在同一套字符串里。

## 同一条运行时路径 {#one-runtime-path}

`AuthorizationInterface` 用**同一套 API** 服务控制器门禁和服务内判断。

- **权限码：** 普通字符串走权限码路径。
- **处理器引用：** 真实的 `FQCN::method` 串走处理器引用路径。
- **can()：** 只返回是否允许，用于分支。
- **authorize()：** 拒绝时抛 HTTP 异常，并发出 `AuthorizationGranted` / `AuthorizationDenied`。

契约面保持小，需要时仍可利用控制器元数据。

## 角色到权限码映射 {#permission-lookup}

- **格式：** `LookupInterface` 用逗号分隔的权限码，便于运行时做快速、精确匹配。
- **代价：** 映射来源（含数据库行、配置串）必须认真归一化。

## 目录与强制执行分离 {#catalogs-stay-separate-from-enforcement}

- **目录与 RBAC 界面：** 用 `PermissionsInterface` 提供扫描、导出和管理台展示所需数据。
- **强制执行事件：** `AuthorizationGranted` 与 `AuthorizationDenied` 随 `authorize()` 路径发出。

两路不混在一处，避免把运行时判断变成大杂烩策略表。
