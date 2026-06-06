---
sidebar_label: Testing 包
description: switon/testing：TestCase、测试容器、EventDispatcher 与 tests/run.sh。
---

# Testing 包 {#testing-package}

`switon/testing` 为 Switon 包测试提供贴近运行时的容器、共享 `TestCase` 与包级 runner。

**关键特性：**

- **共享 `TestCase`：** `Switon\Testing\TestCase` 构建测试容器、运行 `setUpContainer()`，并对测试类做自动注入。
- **务实默认绑定** — 行为重要的保留框架服务，确定性重要的使用测试替身。
- **事件收集** — `Switon\Testing\EventDispatcher` 记录已派发事件，仍可转发给监听器。
- **包级 runner** — `bash tests/run.sh ...` 在不同布局下统一单元、集成与覆盖率运行。
- **Provider 发现** — 从 composer 元数据注册包内 Service Provider，无需完整 app boot。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+、PHPUnit 12+，以及框架测试栈（`switon/core`、`switon/di`、`switon/event`、`switon/composer-extra` 与 PSR 容器/日志/事件相关包）。

```bash
composer require --dev switon/testing
```

---

## 快速开始 {#quick-start}

继承 `Switon\Testing\TestCase`，用 `$this->make()` 创建被测对象，并断言行为。

```php
namespace App\Tests;

use Switon\Testing\TestCase;

final class UserServiceTest extends TestCase
{
    public function testRegisterCreatesUser(): void
    {
        $service = $this->make(UserService::class);

        $user = $service->register('test@example.com');

        self::assertSame('test@example.com', $user->email);
    }
}
```

若某个依赖须在创建被测对象前替换，请重写 `setUpContainer()`。

通过共享 runner 运行套件：

```bash
bash tests/run.sh unit
```

---

## 核心概念 {#core-concepts}

### TestCase {#test-case}

`Switon\Testing\TestCase` 创建 `Switon\Testing\Container\Container`，调用 `setUpContainer()`，再对带 `#[Autowired]` 的属性自动注入。共享引导**默认**关闭协程运行时；需要协程行为的套件请显式开启。

对被测对象请使用 `$this->make()`，以便从测试容器解析。

### 容器默认绑定 {#container-defaults}

测试容器预配置多数包测试常见契约：

- `Psr\Log\LoggerInterface` → `Switon\Testing\MockLogger`
- `Switon\Core\ConsoleInterface` → `Switon\Testing\Mock\MockConsole`
- `Switon\Core\RandomInterface` → `Switon\Testing\MockRandom`
- `Switon\Eventing\EventDispatcherInterface` → `Switon\Testing\EventDispatcher`
- `Psr\EventDispatcher\EventDispatcherInterface` → 同上测试分发器绑定
- `Switon\Core\ContextManagerInterface` → `Switon\Testing\InMemoryContextManager`
- `Switon\Core\App` → `id=test-app`、`env=test`、`debug=true`
- `Switon\Core\TranslatorInterface` → `Switon\Testing\Mock\StubTranslator`

同时保留真实文件系统、时钟、类扫描与 app 等绑定；若容器尚无 `PathAlias`，会创建临时的 `@view`、`@public` 与 `@runtime` 别名。

### 事件派发 {#event-dispatching}

`Switon\Testing\EventDispatcher` **始终**收集事件；默认仍会转发给真实分发器。

当测试只需验证派发、不想让监听器产生副作用时，调用 `disableEventDispatching()`。`getContext()` 仍来自真实分发器。若分发器绑定本身应「只收集不转发」，使用 `Switon\Testing\MockEventDispatcher`。

### Provider 注册 {#provider-registration}

容器从 composer extra 元数据发现 Switon Service Provider，并**仅**调用 `register()`。这使接线贴近运行时，又避免完整 boot 期副作用。也会注册资源别名，但**不**调用 provider `boot()`。

### 测试替身 {#test-doubles}

包内还提供 `Switon\Testing\MockClock`、`Switon\Testing\MockListenerProvider`、`Switon\Testing\Mock\MockCache`、`Switon\Testing\MockLogger`、`Switon\Testing\MockRandom` 与 `Switon\Testing\Mock\StubTranslator` 等。需要时在测试中显式绑定。

---

## 配置 {#configuration}

测试行为主要由容器接线与 runner 参数驱动。

### Runner 入口 {#runner-entrypoint}

在**包根目录**使用包内 runner：

```bash
bash tests/run.sh
bash tests/run.sh unit
bash tests/run.sh integration
bash tests/run.sh all
bash tests/run.sh --fast
bash tests/run.sh --coverage
```

- `unit` 与 `integration` 选择对应 PHPUnit 套件；
- `all` 显式跑完整套件；
- `--fast` 为 unit 快捷方式；
- `--coverage` 将 HTML 覆盖率写入 `tests/coverage`；
- 快捷解析**仅**读取第一个位置参数。

### Provider 自动注册 {#provider-auto-registration}

若要在创建测试容器**之前**关闭 provider 发现，设置 `SWITON_TESTS_DISABLE_PROVIDER_AUTO_REGISTER=1`。任意非空值都会关闭自动注册。

---

## 使用注意 {#keep-in-mind}

- 依赖变更优先放在 `setUpContainer()`。
- 优先断言行为，而非私有状态。
- 不希望监听器运行时请使用 `disableEventDispatching()`。
- 日常包测试优先使用 `bash tests/run.sh ...`，少直接调用 `vendor/bin/phpunit`。

---

## 下一步 {#next}

- **快速答疑：** [FAQ](faq.md#testing-faq)
- **设计取舍：** [设计说明](design.md#design-rationale)
- **数据库测试模式：** [测试数据库相关代码](../db/testing.md#testing-database-code)
- **应用接线：** [为已有应用添加测试](../guides/add-testing-to-a-working-app.md#add-testing-to-a-working-app)
