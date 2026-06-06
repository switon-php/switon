---
description: 任务优先路线：在一条已工作的业务路径上调用第三方或内部上游 API，而不过早让控制器、重试或传输细节成为应用中心。
---

# 调用上游 API {#call-an-upstream-api}

当某条已工作的业务路径现在应调用第三方或内部上游 API，且不希望过早把控制器、重试或传输细节变成应用中心时，使用本指南。

## 选择首个出站 HTTP 边界 {#http-boundary}

从与真实集成问题相匹配的边界开始。

| 边界 | 最适合 | 注意 |
|---|---|---|
| [HTTP Client](../http-client/README.md) | 一个应用服务可直接调用一个上游，且调用方能等待结果 | 第一条 JSON API 集成的最强默认；类型化异常已建模状态与传输失败 |
| [Factory](../di/factory.md) | 不同上游需要不同的默认超时、连接池、代理或 TLS 设置 | 保持应用服务边界不变；仅客户端选择改变 |
| [Queue](../queue/README.md) | 面向用户的延迟、重试需求或进程分离比立即完成更重要 | 保持同一应用网关，仅改变生产者到 worker 的交接 |
| [Log](../log/README.md) | 调用已能工作，下一问题是可见超时、上游状态或失败趋势 | 日志与指标应观察接缝，而不是替代其失败策略 |

一种常见应用形态：

- **Controller / CLI / 任务** 决定业务路径需要上游数据
- **应用网关服务** 拥有请求构建、失败映射与响应解析
- **`Switon\HttpClient\HttpClientInterface`** 执行出站调用
- **`Switon\Di\Factory`** 仅当命名上游客户端需要不同默认值时
- **`Queue`** 仅当调用方不应等待上游往返时

## 推荐构建顺序 {#build-order}

### 1. 在一条业务动作已清晰之后再开始 {#build-business-action}

不要让第一次上游集成定义你的控制器或作业形态。
先弄清哪条业务动作需要外部数据或远程副作用，再把该需求包在一个应用拥有的网关或服务中。

### 2. 在业务逻辑与传输之间保持一个应用网关 {#build-gateway}

不要在许多控制器、命令或 worker 中直接调用 `Switon\HttpClient\HttpClientInterface`。
保持一个边界，如 `CatalogGatewayInterface`、`BillingGatewayInterface` 或 `AddressLookupServiceInterface`，让请求构建、头与失败映射集中在一处。

### 3. 对常规 API 从 JSON 快捷方法开始 {#build-json}

在常见路径上使用 `get/post/put/patch/delete`，用 `$response->json()` 读取载荷。
仅在做头或存在性检查时使用 `head()`。
仅当上游期望表单数据、纯文本、webhook 式载荷或其他显式内容契约时使用 `request()`。

### 4. 把失败当作契约的一部分 {#build-failure-policy}

第一次集成应至少命名一种 HTTP 状态失败与一种传输失败。
典型的首批情况：

- `Switon\HttpClient\Exception\NotFoundException` — 上游记录不存在
- `Switon\HttpClient\Exception\TooManyRequestsException` — 调用方限流或提供商配额应清晰暴露
- `Switon\HttpClient\Exception\TimeoutException` 或 `ConnectTimeoutException` — 延迟或可达性应记录并稍后重试
- `Switon\HttpClient\Exception\BadResponseException` — 常规 JSON 路径收到错误响应形态

### 5. 全局设小默认值，再在局部覆盖异常请求 {#build-timeouts}

在 `switon.yml` 中从一个全局 `timeout`、`connect_timeout`、`verify_peer` 与 `pool_size` 开始。
然后对少数慢、走代理或不寻常的请求使用 `RequestOptions::of(...)`。
这样默认路径可读，同时仍让异常的延迟策略可见。

### 6. 仅在不同上游确实需要不同默认值时才拆分命名客户端 {#build-named-clients}

若每个上游共享一套小超时与网络策略，先保持一个客户端。
仅当某个上游需要比其他人更慢的超时、不同代理或不同连接池大小时，再添加 `Switon\Di\Factory`。

### 7. 仅当等待成为问题时，才把同一网关放到持久化作业后面 {#build-queue}

若调用方应在不等待上游往返的情况下完成，保持同一应用网关，只改变交接。
那是使用[添加持久化后台工作](add-durable-background-work.md)的时机，而不是把请求构建逻辑搬进队列作业的理由。

### 8. 在重试平台或提供商架构之前停下 {#build-stop}

第一个出站 HTTP 成果**不需要**通用 SDK 层、多提供商回退或广泛的重试编排。
在扩大系统之前，先让一条上游路径可信。

## 最小接线 {#minimal-wiring}

仅用短代码展示业务逻辑、应用拥有的网关代码与出站 HTTP 传输之间的接缝。

下文中的 `InventoryGatewayInterface`、`InventoryGateway`、`CheckoutService` 仅为示例。稳定部分是 Switon 的 `HttpClientInterface` 边界、`RequestOptions` 覆盖路径，以及用于命名客户端的可选 `Factory`。

### 为上游契约保持一个应用网关 {#wiring-gateway}

先定义一个应用拥有的边界：

```php
namespace App\Gateway;

interface InventoryGatewayInterface
{
    public function reserve(string $sku, int $quantity): array;
}
```

用 `Switon\HttpClient\HttpClientInterface` 与显式失败处理实现：

```php
namespace App\Gateway;

use Psr\Log\LoggerInterface;
use Switon\Core\Attribute\Autowired;
use Switon\HttpClient\Exception\BadResponseException;
use Switon\HttpClient\Exception\TimeoutException;
use Switon\HttpClient\Exception\TooManyRequestsException;
use Switon\HttpClient\HttpClientInterface;
use Switon\HttpClient\RequestOptions;

final class InventoryGateway implements InventoryGatewayInterface
{
    #[Autowired] protected HttpClientInterface $httpClient;
    #[Autowired] protected LoggerInterface $logger;

    public function reserve(string $sku, int $quantity): array
    {
        try {
            $response = $this->httpClient->post(
                'https://inventory.example.com/api/reservations',
                ['sku' => $sku, 'quantity' => $quantity],
                options: RequestOptions::of([
                    RequestOptions::TIMEOUT => 5,
                    RequestOptions::CONNECT_TIMEOUT => 2,
                ])
            );

            return $response->json();
        } catch (TooManyRequestsException $e) {
            $this->logger->warning('Inventory upstream throttled reservation', [
                'sku' => $sku,
                'quantity' => $quantity,
                'exception' => $e,
            ]);
            throw $e;
        } catch (TimeoutException|BadResponseException $e) {
            $this->logger->error('Inventory upstream failed reservation', [
                'sku' => $sku,
                'quantity' => $quantity,
                'exception' => $e,
            ]);
            throw $e;
        }
    }
}
```

职责划分保持清晰：

- 网关决定**如何调用上游契约**
- `HttpClientInterface` 决定**如何执行请求**
- 日志决定**成功与失败如何变得可见**

### 把业务服务放在网关边界之上 {#wiring-service}

从应用业务逻辑调用网关，而不是直接从控制器调用：

```php
namespace App\Service;

use App\Gateway\InventoryGatewayInterface;
use Switon\Core\Attribute\Autowired;

final class CheckoutService
{
    #[Autowired] protected InventoryGatewayInterface $inventory;

    public function reserveItem(string $sku, int $quantity): array
    {
        return $this->inventory->reserve($sku, $quantity);
    }
}
```

这样 HTTP 细节可从 HTTP、CLI、定时任务或队列消费者复用。

### 保持默认客户端配置小而显式 {#wiring-config}

在 `switon.yml` 中从一个共享默认值开始：

```yaml
# switon.yml
Switon\HttpClient\HttpClientInterface:
  timeout: 3
  connect_timeout: 1
  verify_peer: true
  pool_size: 4
```

仅当网络边界确实需要时再添加 `proxy` 或 `cafile`。
完整选项面见 [HTTP Client configuration discovery](../http-client/README.md#option-discovery)。

### 仅当上游契约不是常规 JSON 路径时使用 `request()` {#wiring-raw-request}

对表单编码的令牌端点或类似契约，保持底层调用显式：

```php
use Switon\HttpClient\HttpClientInterface;

$response = $this->httpClient->request(
    'POST',
    'https://identity.example.com/oauth/token',
    ['grant_type' => 'client_credentials'],
    [HttpClientInterface::HEADER_CONTENT_TYPE => HttpClientInterface::CONTENT_TYPE_FORM]
);

$token = $response->text();
```

当载荷与响应处理不应走 JSON 快捷路径时，这是正确的接缝。

### 仅在默认值分化后再拆分命名客户端 {#wiring-factory}

当两个上游需要不同默认值时，用 `Switon\Di\Factory`，而不是在服务里硬编码传输策略：

```yaml
# switon.yml
Switon\HttpClient\HttpClientInterface:
  class: Switon\Di\Factory
  default:
    timeout: 3
    connect_timeout: 1
    verify_peer: true
    pool_size: 4
  billing:
    timeout: 10
    connect_timeout: 2
    verify_peer: true
    pool_size: 8
```

```php
use Switon\Core\Attribute\Autowired;
use Switon\HttpClient\HttpClientInterface;

final class BillingGateway
{
    #[Autowired] protected HttpClientInterface $billing;
}
```

这样槽位名在代码中，传输选择在配置中。使用 `Factory` 时，自动注入的属性名 `$billing` 选择 `billing` 客户端条目。

## 初期应避免 {#pitfalls}

有意让第一条出站 HTTP 路径保持狭窄。

过早避免这些：

- **在控制器、命令或队列作业中直接调用上游 API** — 保持一个应用网关，让头、超时与 catch 集中
- **对常规 JSON API 从 `request()` 开始** — 快捷方法才是预期的首条路径
- **把非 2xx 响应当作正常返回值** — `404`、`429` 与其他非 2xx 已作为类型化异常抛出
- **在已知慢端点上仍用一条短默认超时** — 对异常请求用 `RequestOptions::of(...)`，而不是默默指望默认值适合所有情况
- **在默认值尚未分化前就把每个上游拆成命名客户端** — 先保持一个客户端，仅在配置策略分化时再扩展
- **假设连接池对不断变化的主机都有帮助** — 复用是按主机的，不是一个全局传输桶
- **在一条调用可观察之前就设计重试、熔断或多提供商故障转移** — 先证明一条成功路径与一条可见失败路径

初学者在完成第一条上游集成路径后应有这样的感受：

> 我知道出站 HTTP 应放在哪里、哪种请求模式适合契约，以及失败应如何在应用代码中暴露。

## 深入阅读 {#go-deeper}

- **深入请求模式、类型化异常、上传与连接池：** [HTTP Client](../http-client/README.md)
- **用配置拆分多个上游，而不是硬编码传输策略：** [Factory](../di/factory.md) · [DI](../di/README.md)
- **把慢或重试敏感的上游作业移出请求路径：** [添加持久化后台工作](add-durable-background-work.md) · [Queue](../queue/README.md)
- **在网关边界周围加一小套可信测试：** [为已有应用添加测试](add-testing-to-a-working-app.md#add-testing-to-a-working-app)
- **更清晰地观察超时、状态与传输失败：** [Log](../log/README.md)
- **构建将调用此网关的入站请求路径：** [构建 API](build-an-api.md#build-an-api) · [HTTP](../http/README.md)
- **在上游支撑的路由稳定后对齐你自己的公开 API 契约：** [添加 API 文档](add-api-docs.md#add-api-docs)· [OpenAPI](../openapi/README.md)
