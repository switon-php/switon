---
sidebar_label: 配置与约定
description: 读懂 Switon 常见配置与示例语法：switon.yml、${APP_ENV:-prod}、#cache、@view、同命名空间 DI。
---

# 理解配置与约定 {#config-and-conventions}

如何阅读 Switon 常见配置与示例语法，例如 `switon.yml`、`${APP_ENV:-prod}`、`#cache`、`@view`，或同命名空间 `XxxInterface` → `Xxx`。

## 最短阅读模型 {#reading-model}

看到 Switon 文档时，先记住五条：

1. 根目录 `switon.yml` 是常态应用配置文件
2. 每个顶层 YAML 键通常对应一个 service id 或契约
3. `${VAR}` 与 `${VAR:-default}` 读取环境变量
4. `#name` 通常表示「当前契约下的命名 service 实例」
5. `@app/...`、`@view/...` 是路径别名，不是字面以 `@` 开头的文件夹

只记住这些，多数初学者示例就足够继续往下读。

## 如何读 `switon.yml` {#yml}

多数应用的 `switon.yml` 在项目根目录。

把下面块：

```yaml
Switon\Http\ExceptionDispatcherInterface:
  handlers:
    App\Exception\MaintenanceException: App\ExceptionHandler\MaintenanceHandler
```

读作：

- `Switon\Http\ExceptionDispatcherInterface` = 正在配置的对象
- `handlers` = 该对象的一个选项
- 每个嵌套项 = 该选项的一个值

另一例：

```yaml
Psr\SimpleCache\CacheInterface:
  prefix: 'tasks:list:'
  redisCache: '#cache'
```

读作：

- 配置 `Psr\SimpleCache\CacheInterface`
- 将其 `prefix` 设为 `tasks:list:`
- 将其 `redisCache` 槽位指向命名 Redis 实例 `#cache`

捷径：顶层键 = 配置谁；嵌套键 = 如何行为。

## 常见配置语法 {#common-config-syntax}

### 环境占位符 {#environment-placeholders}

```yaml
env: ${APP_ENV:-prod}
uri: ${REDIS_URI:-redis://127.0.0.1:6379/0}
```

读作：

- `${APP_ENV}` = 使用环境变量 `APP_ENV`
- `${APP_ENV:-prod}` = 使用 `APP_ENV`；缺失或为空时回退为 `prod`

这是 bootstrap 期间准备的配置占位语法，不是 PHP 语法，也不是需要在 YAML 里手动执行的 shell 代码。

### 命名 service 引用 {#named-service-references}

```yaml
redisCache: '#cache'
```

`#cache` 表示「当前契约下的命名实例 `cache`」。

在如下配置中：

```yaml
Switon\Redis\ClientInterface:
  class: Switon\Di\Factory
  default:
    uri: redis://localhost:6379/0
  cache:
    uri: redis://localhost:6379/1
```

框架会展开为：

- 默认 Redis client
- 命名实例 `Switon\Redis\ClientInterface#cache`

因此 `'#cache'` 只是该命名实例的短引用形式。

### 路径别名 {#path-aliases}

```yaml
layout: '@view/Layouts/Default'
```

别名按字面理解：

- `@app` = 应用根
- `@view` = 视图根，通常默认为 `@app/View`
- `@root` = 项目根
- `@runtime` = runtime 目录

故 `@view/Layouts/Default` 通常指向 `app/View/Layouts/Default` 下某路径，真实模板扩展名稍后解析。

## 常见代码约定 {#common-code-conventions}

### 同命名空间 DI 约定 {#namespace-di-convention}

文档说「同命名空间 DI 约定」时，指这种模式：

```php
namespace App\Service;

interface TaskServiceInterface
{
}

class TaskService implements TaskServiceInterface
{
}
```

若接口与实现于同一命名空间且遵循 `XxxInterface` → `Xxx` 命名，Switon 可在 `switon.yml` 中无需额外显式绑定即可 autowire。

以下情况用显式配置：

- 实现类名不同
- 实现在其他命名空间
- 一个接口有多个实现

### 属性中的命名槽位 {#named-slots-properties}

见到如下代码时：

```php
#[Autowired] protected ClientInterface $cache;
#[Autowired] protected ClientInterface $session;
```

属性名不只是变量名。在 `Factory` 设置下，它们充当槽位名：

- `$cache` → `ClientInterface#cache`
- `$session` → `ClientInterface#session`

因此文档有时说「属性名选择命名实例」。

### 示例中的 `toArray()` {#toarray-in-examples}

指南若写 `$entity->toArray()`，不要假设 Switon 里每个 PHP 对象都有该方法。

通常其一成立：

- 对象是 ORM entity，自带 `toArray()`
- 示例在 cache、渲染或 JSON 输出前有意返回规范化数组形状

若类示例从未定义 `toArray()`，文档应说明方法来源；若没有，视为文档缺口，而非框架普适规则。

## 指南里写「写进 config」时，该配在哪 {#config}

可按下面规则判断：

- 请求级 HTTP 行为 → 通常 `Switon\Http\RequestHandlerInterface`
- HTTP 异常映射 → 通常 `Switon\Http\ExceptionDispatcherInterface`
- cache runtime 选项 → 通常 `Psr\SimpleCache\CacheInterface`
- mail 传输选项 → 通常 `Switon\Mail\MailerInterface`
- 应用元数据与 bootstrap 级值 → 通常根 `switon.yml` 中由 `Kernel` 或 `Core` 拥有的项

若已知契约名，在当前安装版本用 `bash bin/console config:options '<FQCN>'` 最快确认选项面。
