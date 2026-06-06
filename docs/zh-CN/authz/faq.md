---
sidebar_label: 授权 FAQ
description: 授权包常见问题：访客访问、空 #[Authorize]、can 与 authorize、Lookup、Voter。
---

# 授权 FAQ {#authorization-faq}

访问控制里常问的几件事，直接给结论。

## 入门 {#getting-started}

### 怎么放开访客？ {#guest-access}

用 `#[Authorize(Authorize::ANONYMOUS)]`。

### 空的 `#[Authorize]` 算什么？ {#empty-authorize}

**不等于**公开。要公开就写 `Authorize::ANONYMOUS`。留空仍然走鉴权：已登录用户会继续查权限；访客在查之前就会被拒。

### `#[Authorize('@edit')]` 是干嘛的？ {#authorize-reference}

给扫描和目录留一条**权限引用**，真正的放行还是按解析出的处理器路径和 Lookup 规则走。

### `can()` 还是 `authorize()`？ {#can-vs-authorize}

分支里判断用 `can()`；到了 HTTP 边界、要抛错和发事件就用 `authorize()`。

### 角色从哪来？ {#roles-source}

`Switon\Principal\IdentityInterface::getRoles()`。登录怎么做仍在身份层。

## 配置 {#configuration}

### 自定义角色怎么对上权限码？ {#custom-role-permissions}

在 `LookupInterface` 里用逗号分隔权限码，**不要空格**，字符串要和运行时完全一致。

### Voter 怎么挂上？ {#register-voters}

在 `switon.yml` 里给 `Switon\Authorizing\AuthorizationInterface` 配 `voters` 列表。

### 目录或 RBAC 界面怎么做？ {#catalog-ui}

用 `PermissionsInterface`。这是**展示/扫描**用的，和运行时强制执行分开。

## 排错 {#troubleshooting}

### 自定义角色怎么老被拒？ {#custom-role-denied}

核对权限码拼写；核对 Lookup 里是不是不小心加了空格。

### 处理器引用为什么被拒？ {#handler-reference-denied}

只有**真实存在**的 `FQCN::method`（既要有 `\` 又要有 `::`）才当处理器引用；方法不存在会立刻拒绝。`admin::index` 这种没有反斜杠的仍按**权限码**走。

### `401` 和 `403` 怎么分？ {#401-vs-403}

解析后**一套角色都没有** → `UnauthorizedException`（401）；**有角色**但仍不允许 → `ForbiddenException`（403）。

### Voter 能拦住 `SUPERUSER` 吗？ {#voter-block-superuser}

能。Voter 在内置放行逻辑**之前**跑，`false` 就直接拒，和身份里有没有 `SUPERUSER` 无关。

### 什么样才算「处理器引用」？ {#handler-reference-rules}

真实的 `FQCN::method`、含 `\` 和 `::`、方法还得存在。否则一律当普通权限串。

### `AuthorizationGranted` / `AuthorizationDenied` 什么时候发？ {#authz-events}

只有走 `authorize()` 才会发；`can()` 不发。
