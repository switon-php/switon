# 身份作为框架契约 {#identity-as-a-framework-contract}

`Switon\Principal\IdentityInterface` 是框架契约，因为多个边界在认证完成后需要相同的已解析调用方形态。

**为何是亮点：**
- `IdentityInterface` 将身份消费与 session、token、cookie 或 header 解析分离
- 业务代码可通过一个稳定契约读取已解析身份，而无需重复实现传输逻辑
- 授权、HTTP 归属范围（如 `Switon\Http\Attribute\IdentityFilter`）与其他横切规则可共享同一当前调用方来源
- `Switon\Principal\Identity` 将该状态存放在运行时上下文中，使契约保持请求作用域而非传输作用域

## 框架决策 {#the-framework-decision}

Switon 将调用方身份视为已解析的运行时状态，而非 auth 包细节。

因此契约位于 `Switon\Principal\IdentityInterface`，默认实现是 `Switon\Principal\Identity`。
认证代码仍属于所属的 HTTP 或应用边界，但一旦该代码识别了调用方，框架其余部分应消费一种规范化形态：`isGuest()`、`getId()`、`getName()` 与 `getRoles()`。

这使契约在应用日后从基于 session 的认证、基于 JWT 的认证、混合流程或其他机制切换时仍保持稳定。

## 为何业务代码应读契约而非传输层 {#code-contract-transport}

业务代码通常需要当前调用方，而非原始传输细节。

这意味着控制器、服务或监听器在 auth 层调用 `set()` 之后可直接读取 `IdentityInterface`。
它无需重新解析 cookie、bearer token、请求属性或应用专属载荷形态，就能回答常见问题，例如：

- 当前调用方是谁
- 调用方是否为访客
- 调用方有哪些规范化角色

这是主要框架价值：身份在请求边界解析一次，然后在别处作为普通依赖复用。

## 为何横切特性依赖它 {#cutting-features-depend}

框架特性需要共享身份源，否则会漂向各层重复规则。

`Switon\Http\Attribute\IdentityFilter` 通过 `IdentityInterface::getId()` 在 HTTP 边界对列表 Criteria 保持归属范围由服务端所有。
授权使用 `IdentityInterface::getRoles()`，使 `#[Authorize]` 与 `AuthorizationInterface` 可在不知应用如何登录调用方的情况下执行访问控制。
同一契约也使当前用户端点与其他请求级决策对齐于一种调用方模型，而非多种传输专属模型。

因此 `IdentityInterface` 属于框架层：它是让认证可插拔、同时身份感知行为保持一致的共同接缝。

## 另见 {#see-also}

- **在请求边界加载身份：** [添加认证](../guides/add-authentication.md)
- **在控制器与服务访问规则中使用共享契约：** [Authorization 包](../authz/README.md)
- **查看依赖此契约的一个 HTTP 归属边界：** [身份感知的归属安全](identity-aware-ownership.md)
- **使用身份与租户状态的所属包：** [Principal 包](../principal/README.md)
