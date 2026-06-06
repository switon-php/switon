---
description: 在变更扩散到 HTTP、数据、认证或后台行为之前，为已有一条可用路径的应用建立一小套可信测试。
---

# 为已有应用添加测试 {#add-testing-to-a-working-app}

当应用已有一条可用路径，且你希望在变更扩散到 HTTP、数据、认证或后台行为之前，先建立一小套可信的测试路线时，使用本指南。

## 选定首先要证明什么 {#thing-prove}

从若静默回归会最伤你的风险出发。

| 首要目标 | 最适合 | 注意 |
|---|---|---|
| service 行为 | 业务规则、分支、映射或副作用最可能变化 | 最佳默认首步；断言放在可观察结果上，如返回值、抛出的异常、发出的事件或持久化状态；使用 `Switon\Testing\TestCase` |
| 数据库行为 | SQL、约束、仓储行为或事务边界必须真实 | 使用 [Testing Database Code](../db/testing.md)；对 service 协作者可 mock，但**不能**证明 SQL 或约束行为 |
| HTTP 邻接行为 | 请求输入对象形态、授权标记或响应协调必须保持薄且可读 | 保持控制器精简；若控制器测试显得笨重，先把行为下沉到 service |

一条实用的首个测试切片常包括：

- 一条主成功路径的 service 测试
- 同一 service 的一条失败路径测试
- 仅在真实持久化行为重要时，才加一条数据库支撑测试

## 推荐构建顺序 {#build-order}

### 1. 在一条路径已真实之后开始 {#build-real-path}

测试在保护真实边界时最有用，而非保护推测中的设计。
在一条 API、CLI 或已存数据路径已按预期工作后开始。

### 2. 把大多数应用行为放在 service 接缝之后 {#build-service-seam}

若控制器或命令仍拥有真实逻辑，测试要么嘈杂，要么与传输细节耦合过紧。
先把业务行为移入 service，再让 HTTP 或 CLI 保持为薄的协调层。

### 3. 在 `Switon\Testing\TestCase` 上增加一条快速测试 {#build-fast-test}

以 [Testing](../testing/README.md) 为常规首选。
用 `$this->make()` 创建被测对象，在 `setUpContainer()` 中做替换，并断言读者无需追踪私有状态即可理解的行为。

### 4. 仅在数据边界才是风险时增加真实数据库测试 {#build-db-test}

当正确性依赖 SQL 形态、约束或仓储行为时，通过 [Testing Database Code](../db/testing.md) 增加一条聚焦的真实数据库用例。
不要仅因应用最终会存数据，就把每条测试都变成数据库测试。

### 5. 保持传输层相关测试狭窄 {#build-transport-narrow}

HTTP、认证与 CLI 路径仍然重要，但首个测试目标不是模拟每个运行时细节。
证明薄的控制器或命令协调，再把大多数分支与变更检查放在该传输层之下。

### 6. 仅在首个切片可信后再扩展 {#build-expand-later}

一旦存在一条稳定的测试切片，再向认证流程、队列入队、周期性工作或面向文档的路由扩展。
一条可信的窄切片胜过宽泛、易抖的覆盖。

## 最小接线 {#minimal-wiring}

仅用短示例展示共享测试容器、应用 service 边界与真实数据库用例之间的接缝。
下文中的 `UserService`、`RegisterUser`、`UserRepository` 等应用级名称仅为示意。Switon 测试符号与 runner 表面才是稳定部分。

### 快速 service 级路径 {#wiring-service}

从 `Switon\Testing\TestCase` 起步，让被测对象留在容器管理的接线上：

```php
use Switon\Testing\TestCase;

class RegisterUserTest extends TestCase
{
    public function testRegisterCreatesUser(): void
    {
        $service = $this->make(RegisterUser::class);

        $user = $service->handle('dev@example.com');

        $this->assertSame('dev@example.com', $user->email);
    }
}
```

`$this->make()` 来自 `Switon\Testing\TestCase`。它通过共享测试容器解析被测对象，因此被测 service 与其协作者使用与正常运行时相同的 DI 规则创建。

通过共享 runner 运行快速套件：

```bash
bash tests/run.sh unit
```

若应用未提供 `tests/run.sh`，使用项目已有的测试入口，并保持快速 service 测试与较重数据库测试之间的同样划分。

当业务行为比存储细节更重要时，这是最佳首选。

### 在被测对象解析前替换一个依赖 {#wiring-replacement}

当某个依赖应变得确定时，在 `setUpContainer()` 中替换：

```php
use Psr\Log\LoggerInterface;
use Switon\Testing\MockLogger;
use Switon\Testing\TestCase;

class RegisterUserTest extends TestCase
{
    protected MockLogger $logger;

    protected function setUpContainer(): void
    {
        parent::setUpContainer();

        $this->logger = new MockLogger();
        $this->container->set(LoggerInterface::class, $this->logger);
    }
}
```

`setUpContainer()` 同样是 `Switon\Testing\TestCase` 的钩子，不是从零发明的 PHPUnit 方法。测试基类创建容器、调用 `setUpContainer()`，然后才自动装配测试实例及之后通过 `$this->make()` 创建的被测对象。

`MockLogger` 来自 `switon/testing` 包（`Switon\Testing\MockLogger`）。当你需要确定性的 logger 替身而非正常运行时 logger 绑定时使用它。

这样测试贴近真实 DI 行为，同时让某个协作者可预测。

### 当必须证明持久化时的真实数据库路径 {#wiring-db}

当风险是真实存储行为时，使用 [Testing Database Code](../db/testing.md)：

```php
use Switon\Db\ClientInterface;
use Switon\Di\Factory;
use Switon\Testing\TestCase;

class UserRepositoryTest extends TestCase
{
    protected function setUpContainer(): void
    {
        (new Factory([
            'default' => ['uri' => 'sqlite::memory:'],
        ]))->register(ClientInterface::class, $this->container);
    }
}
```

```bash
bash tests/run.sh integration
```

当 mock 会掩盖你实际需要信任的东西时，使用这一层。

## 初期应避免什么 {#pitfalls}

有意让第一条测试路径保持狭窄。

过早避免：

- **先测不稳定流程** — 在追逐移动目标之前，先保护一条已能工作的路径
- **把核心逻辑留在控制器再测控制器复杂度** — 把逻辑移入 service，让 HTTP 保持薄
- **每条测试都用真实数据库** — 仅留给 mock 无法证明的 SQL、约束与仓储行为
- **在 `$this->make()` 之后替换依赖** — 在被测对象或其协作者解析之前，在 `setUpContainer()` 中注册变更
- **断言私有状态或偶然接线细节** — 优先使用返回值、持久化状态、发出的事件、异常或其他可观察结果
- **把首个有用测试切片当作完整覆盖计划** — 从一条可信切片扩展，而非一次性做完一切

完成第一条测试路径后，初学者应能感到：

> 我知道哪段应用行为已被保护、为何在该层级测试，以及下一步应往哪里加宽。

## 继续深入 {#go-deeper}

- **在第一条 API 或页面路径之后衔接：** [构建 API](build-an-api.md#build-an-api) · [构建 HTML 页面](build-html-pages.md#build-html-pages)
- **在已存应用状态变真实之后衔接：** [持久化数据](persist-data.md#persist-data)
- **继续使用共享测试容器与 runner 表面：** [Testing](../testing/README.md)
- **证明 SQL、仓储行为或事务边界：** [Testing Database Code](../db/testing.md) · [DB](../db/README.md)
- **在请求行为增长时保持 HTTP 处理器精简：** [添加横切 HTTP 行为](add-cross-cutting-http-behavior.md#add-cross-cutting-http-behavior) · [HTTP](../http/README.md)
- **在基础路径已可测之后添加登录与受保护端点：** [添加认证](add-authentication.md)
- **仅在行为已可信之后发布稳定路由：** [添加 API 文档](add-api-docs.md#add-api-docs)
- **从控制台复用同一应用行为：** [添加 CLI](add-cli.md)
