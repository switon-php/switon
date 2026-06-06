# 身份感知的归属安全 {#identity-aware-ownership}

Switon 在列表查询与单实体操作上使用同一套身份感知归属模型，使同一动作级规则既能约束「只显示我的记录」，也能约束「只加载我的那条记录」。

**为何是亮点：**
- `Switon\Http\Attribute\IdentityFilter` 在 HTTP 边界以服务端所有的方式保持归属范围
- 同一规则形态作用于 `Switon\Http\Criteria`；实体绑参使用实体上的 `#[Switon\Orm\Attribute\Owner]` 元数据
- 单条记录的归属泄漏比列表泄漏更安静，因此更受益于框架默认检查
- `Switon\Principal\IdentityInterface` 为多层提供稳定的当前身份数据源

## 本文强调的要点 {#highlights}

- `Switon\Http\Attribute\IdentityFilter` 在 HTTP 边界增加服务端所有的归属范围。
- `IdentityFilter` 用于 `Switon\Http\Criteria`；ORM 实体绑参使用实体类上的 `#[Switon\Orm\Attribute\Owner]`。
- 单实体泄漏比列表泄漏更易被忽略，因此 Switon 在两条路径上都保留归属检查。
- `Switon\Principal\IdentityInterface` 让这些部分共享同一当前身份数据源。

## 一个动作级归属规则 {#action-ownership-rule}

`Switon\Http\Attribute\IdentityFilter` 是用于「仅我自己的资源」类动作的方法属性。

默认使用 `created_by` 字段。当你的实体与输入类型已使用其他已验证字段作为归属列时，也可传入例如 `admin_id`。

重要的不是字段名本身，而是同一动作级声明成为服务端规则，而非客户端建议。

## 列表与查询范围 {#list-query-scoping}

在列表与搜索动作上，`Switon\Http\CriteriaResolver` 将 `Switon\Http\Attribute\IdentityFilter` 应用于 `Switon\Http\Criteria`。

这意味着：

- 当前身份 id 会作为过滤条件加入
- 客户端提供的归属过滤会被服务端值覆盖

这样列表范围保持简单：动作声明一次归属，请求无法通过修改传入 query 中的 `created_by`、`admin_id` 或其他已验证归属字段来扩大范围。

## 单实体加载、编辑与删除范围 {#single-delete-scoping}

同一归属模型也覆盖通过 `Switon\Orm\EntityResolver` 的实体参数绑定。

当动作参数是 `Switon\Orm\Entity` 子类且请求包含主键时，若实体通过 `#[Switon\Orm\Attribute\Owner]` 声明了归属列，解析器不会仅按主键加载。查找会同时包含：

- 主键
- 归属字段约束为 `Switon\Principal\IdentityInterface::getId()` 的当前身份

默认 `created_by` 用 `#[Owner]`；其他列传入字段名；仅按主键加载用 `#[Owner(null)]`（例如管理端用显式 Repository 查询的动作）。

实践中，现有行必须同时匹配请求 id 与当前所有者。否则加载会通过 `Switon\Orm\RepositoryInterface::firstOrFail()` 失败，并以 `Switon\Orm\Exception\EntityNotFoundException` 结束。

对于创建与更新类载荷，解析器在 hydration 之前也会在绑定实体数据中强制归属字段。动作不需要单独的「信任此传入所有者 id」分支。

## 为何单实体泄漏更难察觉 {#leaks-harder-notice}

列表泄漏通常很显眼。用户会看到明显不属于他们的行。

单实体泄漏更安静。按 id 加载、编辑或删除一条记录的端点在常规测试中仍可能看起来正确，因为它返回一个看似合理的对象，而不是明显错误的列表。这使得遗漏归属检查更容易上线、更难发现。

Switon 的归属模型在此重要，因为它不止于列表过滤。按 id 加载单条记录时，实体绑参通过 `#[Owner]` 应用同一归属列。

## 为何 `Switon\Principal\IdentityInterface` 使这一切成为可能 {#identityinterface-makes-possible}

统一部分是 `Switon\Principal\IdentityInterface`。

`Switon\Http\Attribute\IdentityFilter` 通过 `IdentityInterface::getId()` 读取当前身份。同一规范化契约也在别处用于 `isGuest()`、`getName()` 与 `getRoles()` 等认证状态与角色。

这对归属安全重要，因为 HTTP 查询辅助、类型化输入绑定、ORM 实体绑定、授权与其他横切代码不必知道应用是用 session、token 还是其他机制认证请求。它们只需一个稳定的身份契约。

没有该共享契约，归属规则往往会分裂成各层重复的局部逻辑。有了它，Switon 可保持规则小巧：一次解析当前身份，然后在多个边界一致复用。

## 实践要点 {#practical-takeaway}

若动作应表示「此用户只能操作自己的记录」，列表用 `Switon\Http\Attribute\IdentityFilter` 声明范围，实体用 `#[Switon\Orm\Attribute\Owner]` 保持同一归属列；创建路径在应用代码里写入归属，不要从请求入参信任 owner 字段。

这是主要安全属性：列表端点与单条记录端点遵循同一身份感知归属列，而不是漂向分离、更易遗漏的检查。

## 另见 {#see-also}

- [类型化输入绑定](../http/typed-input-binding.md) — 请求输入与实体参数绑定
- [HTTP 包](../http/README.md) — 请求、控制器与输入边界
- [Authz 包](../authz/README.md) — 归属范围之外的授权
- [Switon 基础](../concepts/README.md) — 框架模型与代码放置上下文
