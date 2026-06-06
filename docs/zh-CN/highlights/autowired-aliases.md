# Autowired 命名槽位 {#autowired-aliases}

Switon 将多实例依赖装配视为框架问题，而非业务代码内重复的局部命名纪律。

**要点：**
- `#[Autowired]` 将依赖声明保留在代码中
- 属性名成为稳定的槽位名
- `Switon\Di\Factory` 将一个契约展开为 `Type#name` 服务 ID
- 当目标名仅在运行时可知时，`Switon\Di\NamedLookupInterface` 仍是显式的运行时路径

## 分散的问题 {#the-scattered-problem}

应用常常需要同一能力的多个实例：

- 一个 `Switon\Redis\ClientInterface` 用于缓存，另一个用于 session
- 一个邮件传输用于事务邮件，另一个用于本地测试
- 一个类数据库契约带有不同命名连接或角色

若没有统一的框架模式，团队通常会退回以下之一：

- 注入容器并在行内按不同 ID 获取
- 在应用中硬编码字符串服务 ID
- 仅为重命名实例而发明局部包装服务
- 将实例选择细节推入并不拥有装配的业务服务

这能工作，但会把实例命名扩散到本应只描述业务依赖的代码中。

## Switon 模式 {#the-switon-pattern}

Switon 让常见情况保持声明式：

- 类型说明需要什么能力
- 属性名说明该能力应填入哪个稳定槽位
- 配置将槽位映射到具体命名实例

因此这些示例可以保持简短：

```php
use Switon\Core\Attribute\Autowired;
use Switon\Redis\ClientInterface;

class MyService
{
    #[Autowired] protected ClientInterface $cache;
    #[Autowired] protected ClientInterface $session;
    #[Autowired] protected ClientInterface $redis;
}
```

配合 `Switon\Di\Factory`，这些属性通过以下方式解析：

- `Switon\Redis\ClientInterface#cache`
- `Switon\Redis\ClientInterface#session`
- 普通 `Switon\Redis\ClientInterface` 作为 `$redis` 的默认回退

重要的不是字符串格式本身，而是稳定的槽位选择留在同一处，不会泄漏到业务流程中。

## 为何是亮点 {#highlight}

该模式用一条默认规则解决反复出现的应用问题：

- **业务代码保持语义化** — `$cache` 与 `$session` 描述角色，而非容器装配细节
- **配置拥有实例选择** — 应用可重定向槽位而无需重写服务逻辑
- **动态查找保持显式** — `Switon\Di\NamedLookupInterface` 用于真正的运行时选择，而非过载普通注入

这比「属性注入很方便」更强：它规范了同一契约如何安全扇出到多个稳定应用角色。

## 边界 {#boundaries}

当槽位在代码中稳定时，此模式最佳。

在以下情况使用属性名槽位：

- 一个服务始终需要同一命名后端
- 槽位名是该服务稳定角色的一部分
- 配置应选择具体实例

在以下情况改用 `Switon\Di\NamedLookupInterface`：

- 目标名来自运行时输入
- 目标名在循环或分派表中变化
- 服务有意做晚期选择

若仅存在一个实例，使用普通类型注入，让默认解析路径保持简单。

## 另见 {#see-also}

- [DI 包](../di/README.md) — DI 概览
- [Autowired](../di/autowired.md) — 解析顺序与属性注入规则
- [Factory](../di/factory.md) — 命名实例注册与别名
- [DI 设计 rationale](../di/design.md) — Switon 为何保持实例选择显式
