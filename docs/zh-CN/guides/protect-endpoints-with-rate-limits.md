---
description: 为已可用的登录、公开 API 或易滥用 action 添加共享请求限制：属性、身份感知键与可选 429 响应策略的最小路径。
---

# 用速率限制保护端点 {#protect-endpoints-with-rate-limits}

当一条可用的登录路径、公开 API 路由或易滥用的 action 现在需要共享请求限制，且你不希望过早把自定义 filter、配额平台或计费档位当作首要答案时，使用本指南。

## 选择第一种速率限制边界 {#limit-boundary}

从实际伤害应用的滥用模式起步。

| 边界 | 最适合 | 注意 |
|---|---|---|
| 单个 action 上的 `#[RateLimit(...)]` | 一个端点如登录、密码重置或搜索是明确热点 | 仅少数路由有风险时的最佳首步 |
| 单个控制器上的 `#[RateLimit(...)]` | 一整族控制器共享相同的公开读或写压力 | 方法级 attribute 仍覆盖类级默认 |
| 配置回退 `limits` | 应用仍小时，希望对未标注 action 有安全网 | 仅当不存在类或方法 attribute 时配置才生效 |
| `RateLimiterInterface::hit()` | 真实键由业务拥有，如手机号、租户、API 密钥或短信目标 | 手动键按原样使用；配置 `prefix` **不**适用 |

一种常见应用形态是：

- **登录或重置端点** — 较紧的 action 级限制
- **公开读端点** — 较宽的控制器级或 action 级限制
- **已认证端点** — [添加认证](add-authentication.md) 就位后，自动按当前身份键控
- **手动 `hit()` 调用** — 仅当 HTTP 路由身份不是真实滥用边界时

## 推荐构建顺序 {#build-order}

### 1. 从一个用户或攻击者真能打到的端点起步 {#build-hot-path}

先选一个真实滥用面：

- 登录
- 密码重置请求
- 公开搜索
- 匿名创建/提交端点

不要在一条风险路径清楚之前，从全局配额理论起步。

### 2. 先保护登录，再扩大到公开读 {#build-login}

登录与恢复端点通常值得第一条紧限制，因为暴力破解压力在那里比大多数业务路由更清晰。
之后，保护会产生成本、噪音或垃圾信息的匿名读或热点写 action。
若应用有 `/api` 等全局路由前缀，控制器映射保持无前缀，并在测试受保护端点之前用 `bash bin/console router:prefix` 确认真实公开 URL。

### 3. 让身份形态跟随真实调用方边界 {#build-identity}

Switon 按以下维度键控自动请求限制：

- 控制器
- action
- 身份
- 规则窗口

对访客，身份是客户端 IP。
对已认证调用方，身份是 `IdentityInterface::getName()`。

这意味着认证真实之后，同一路由会自然区分访客流量与已认证流量。

### 4. 保持首条规则小而可读 {#build-rules}

从你能用一句话解释的一两个窗口起步。
例如：

- 登录尝试 `5/m`
- 公开列表端点 `60/m`
- 需要短窗口与长窗口双重保护时 `['10/m', '100/h']`

仅当短峰值合法且你理解它**只影响第一条规则**时，才添加 `burst`。

### 5. 判断默认 `429` 是否足够 {#build-429}

包已抛出 `RateLimitExceededException`。在正常 HTTP 异常处理中，这会渲染为 `429` 响应。
当客户端只需稳定拒绝时，先保留该默认。
仅当调用方真正需要额外头或应用特定错误措辞时，再添加自定义异常处理器。

### 6. 仅在路由身份不够时移到 `hit()` {#build-hit}

若真实滥用键是手机号、租户或上游 API 密钥，在你自己的 service 边界内使用 `RateLimiterInterface::hit()`。
当路由本身不是真实配额单元时，不要把这类业务键硬塞进路由 attribute。

### 7. 在配额平台设计之前停下 {#build-stop}

第一次速率限制胜利**不需要**计费档位、机器人评分或分析仪表盘。
在扩大系统之前，先让一条登录路径与一条公开路径可信。

## 最小接线 {#minimal-wiring}

下面只用短片段展示端点风险、限制器放置与 HTTP 响应策略之间的接缝。

下文应用级名称如 `AuthController`、`PublicApiController`、`TooManyRequestsHandler` 仅为示例；稳定部分是 Switon 节流 attribute、配置表面与可选异常处理器接缝。

### 保持 Redis 与 throttle 配置明确 {#wiring-config}

在 `switon.yml` 中从最小限制器依赖起步：

```yaml
# switon.yml
Switon\Redis\ClientInterface:
  class: Switon\Di\Factory
  default:
    uri: redis://127.0.0.1:6379/0

Switon\Throttle\RateLimiterInterface:
  enabled: true
  limits: ['60/m']
```

回退 `limits` 仅作安全网。
对真正值得刻意限制的端点使用 action 或控制器 attribute。

### 在登录与其他热点 action 上收紧限制 {#wiring-action}

当一个 action 明显比其他 action 更危险时，使用方法级 `#[RateLimit]`：

```php
namespace App\Controller;

use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Throttle\Attribute\RateLimit;

#[RateLimit('60/m')]
#[RequestMapping('')]
final class PublicApiController
{
    #[PostMapping('/login')]
    #[RateLimit(['5/m', '30/h'])]
    public function loginAction(): array
    {
        return ['ok' => true];
    }

    #[GetMapping('/search')]
    public function searchAction(): array
    {
        return ['items' => []];
    }
}
```

这样常见模式保持可读：

- 一般流量一条控制器级默认
- 登录或其他易滥用 action 一条更紧的方法级规则

若应用加了 `/api` 等全局前缀，公开路由为 `/api/login` 与 `/api/search`，尽管控制器映射仍为 `'/login'` 与 `'/search'`。

### 让认证自动改善键形态 {#wiring-auth}

[添加认证](add-authentication.md) 就位后，已认证请求自动按 `IdentityInterface::getName()` 而非 IP 键控。
这意味着受保护路由可保留同一 `#[RateLimit]` attribute，而有效调用方身份变得更具体。

当身份加载路径本身仍未完成时，使用认证指南。

### 仅对业务拥有的限制器键使用直接 `hit()` {#wiring-hit}

当配额属于手机号、租户或其他领域键时，在 service 边界内节流：

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Throttle\RateLimiterInterface;

final class SmsService
{
    #[Autowired] protected RateLimiterInterface $rateLimiter;

    public function send(int $userId, string $phone): void
    {
        $this->rateLimiter->hit('sms:' . $phone, '5/m');
        $this->rateLimiter->hit('sms:user:' . $userId, ['10/m', '100/h']);
    }
}
```

当路由身份不是真实滥用边界时，这是正确接缝。
记住手动键完全按你传入的方式使用。

### 仅当客户端需要时再自定义 429 响应 {#wiring-handler}

若需要 `Retry-After` 或更明确的 body，通过正常 HTTP 异常处理器接缝映射该异常：

```yaml
# switon.yml
Switon\Http\ExceptionDispatcherInterface:
  handlers:
    Switon\Throttle\Exception\RateLimitExceededException: App\ExceptionHandler\TooManyRequestsHandler
```

```php
namespace App\ExceptionHandler;

use Switon\Core\Attribute\Autowired;
use Switon\Http\ExceptionHandlerInterface;
use Switon\Http\RequestInterface;
use Switon\Http\ResponseInterface;
use Switon\Throttle\Exception\RateLimitExceededException;
use Throwable;

final class TooManyRequestsHandler implements ExceptionHandlerInterface
{
    #[Autowired] protected RequestInterface $request;
    #[Autowired] protected ResponseInterface $response;

    public function handle(Throwable $exception): bool
    {
        if (!$exception instanceof RateLimitExceededException) {
            return false;
        }

        $this->response->setHeader('Retry-After', '60');

        if ($this->request->wantsJson()) {
            $this->response->json(['code' => 429, 'msg' => 'Too Many Requests'], 429);
        } else {
            $this->response->text('Too Many Requests', 'text/plain', 429);
        }

        return true;
    }
}
```

仅当客户端行为真正依赖更明确的 429 表面时，才保留该处理器。
包本身已提供基本 `429` 结果。

## 首条路径上宜避免什么 {#pitfalls}

有意让第一条速率限制路径保持窄。

下列内容不宜过早：

- **在不知道哪些路由是热点之前就保护一切** — 先从登录、重置或明显公开热点起步
- **把类级限制当作可按方法静默关闭** — 没有自己 attribute 的方法仍继承类级规则
- **假设 `burst` 作用于列表中每条规则** — 它只影响第一条规则
- **期望配置默认覆盖显式 attribute** — 方法与类 attribute 优先；仅无 attribute 时回退配置才生效
- **真实配额键由业务拥有时仍用路由 attribute** — 手机号、租户或 API 密钥形态的限制移到 `hit()`
- **期望速率限制头自动出现** — 需要时在异常处理器或响应管线中自行添加

完成第一条速率限制路径后，初学者应能感到：

> 我知道应先保护哪些端点、Switon 如何键控调用方，以及默认 429 何时足够、何时应自己拥有响应表面。

## 继续深入 {#go-deeper}

- **在首条 API 或页面路径之后衔接：** [构建 API](build-an-api.md#build-an-api) · [构建 HTML 页面](build-html-pages.md#build-html-pages) · [HTTP](../http/README.md)
- **在键控的已认证限制有意义之前落定调用方身份：** [添加认证](add-authentication.md)
- **把自定义 429 失败策略或响应钩子放在正确 HTTP 接缝：** [添加横切 HTTP 行为](add-cross-cutting-http-behavior.md)
- **深入 attribute、回退配置、burst 与键组合：** [Throttle](../throttle/README.md)
- **调优 Redis 拓扑与共享计数器：** [Redis](../redis/README.md)
- **公开与受保护路由稳定后文档化 429 行为：** [添加 API 文档](add-api-docs.md)
- **在进一步扩大之前保护第一批易滥用路径：** [为已有应用添加测试](add-testing-to-a-working-app.md#add-testing-to-a-working-app)
