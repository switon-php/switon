---
sidebar_label: Context 实现深潜
description: Switon 如何在 FPM 与 Swoole 下以相同方式实现透明的请求隔离。
---

# Context 实现深潜 {#context-evolution}

Switon 如何在 FPM 与 Swoole 下以相同方式实现透明的请求隔离。

日常用法见 [Context 包](../README.md)。

## 引言 {#introduction}

本文说明 Context 为何存在、机制如何一步步演进，以及 Switon 如何在 FPM 与 Swoole 间保持行为一致。

当你需要**实现层面的理由**而非 API 用法时阅读本文。

---

## 为何 Context 重要 {#why-context-matters}

在长驻运行时中，上下文隔离为何至关重要。

### PHP-FPM 世界 {#php-fpm-world}

传统 PHP-FPM 每个进程处理一个请求。关键特性是**自动隔离**。

```php
class RequestState
{
    protected array $attributes = [];

    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function getAttribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }
}
```

在 FPM 中，每个请求拥有独立内存空间。请求结束时，状态随进程生命周期释放。一个请求无法读到另一个请求的状态。

### Swoole 的挑战 {#the-swoole-challenge}

Swoole 使用长驻进程承载大量并发协程，从而提升吞吐与资源复用：

- 无进程创建开销
- 持久连接（数据库、Redis 等）
- 真正的异步 I/O
- 可承载 1 万+ 并发连接

但由此带来根本问题：**共享内存**。

```php
// Same RequestState class from above

// Request 1 (Coroutine #1): $request->setAttribute('user_id', 123);
// Request 2 (Coroutine #2): $request->setAttribute('user_id', 456);
// Request 1 reads: $request->getAttribute('user_id'); // Returns 456! ❌
```

多个协程可能共享同一个 `RequestState` 实例，因而也共享 `$attributes`。在生产环境中，这表现为跨请求的状态泄漏。

核心问题是：如何在 Swoole 中保持 FPM 式的隔离，又不必写运行时相关的业务代码？

### 理想方案 {#the-ideal-solution}

我们期望的 Context 系统应：

- **透明** — 同一套代码在 FPM 与 Swoole 下都能工作，无需 `if (swoole_running())` 判断
- **自动** — 无需手动跟踪协程 ID 或传递上下文
- **安全** — 不可能在请求之间意外共享状态
- **快速** — 开销极小，不拖慢性能
- **灵活** — 支持继承共享、子作用域隔离，以及适配器自管的连接生命周期标记
- **零配置** — 开箱即用
- **对开发者友好** — API 清晰、IDE 支持好、易于调试
- **集中且可检视** — 所有上下文状态聚合在专用类中，便于审计与排错

Switon 的 Context 系统即围绕这些目标设计。

---

## 总览 {#overview}

这是一份**走读**，不是逐行源码地图。从 `Coroutine::getContext()` 出发，逐步加入命名空间、Context 类、发现机制与双运行时行为。

生产行为请以以下文档为准：

- [Context 包 README](../README.md) — `ContextAware`、`ContextManager`、隔离模式、继承。
- [Context 设计](../design.md) — 存储模型与运行时行为。

---

## 根本问题 {#problem}

本设计要解决的核心并发问题。

### FPM：天然隔离 {#fpm-natural-isolation}

在传统 PHP-FPM 中，每个请求在独立进程中运行：

```php
class RequestState
{
    protected array $attributes = [];

    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }
}

// Request 1 (Process A): $request->attributes = ['user_id' => 123]
// Request 2 (Process B): $request->attributes = ['user_id' => 456]
// ✅ No conflict: Different processes, different memory
```

### Swoole：共享内存问题 {#swoole-memory-problem}

在 Swoole 中，多个请求作为协程在同一进程内运行：

```php
// Same RequestState class

// Request 1 (Coroutine #1): $request->attributes = ['user_id' => 123]
// Request 2 (Coroutine #2): $request->attributes = ['user_id' => 456]
// ❌ Request 1 now sees user_id = 456 (data corruption!)
```

**挑战：** 如何在不改应用代码的前提下隔离每请求状态？

---

## 演进：从朴素到生产 {#evolution}

本节一步步搭建 Context 系统，并记录每一步会暴露什么问题。

每一步修复一个问题，再引出下一约束。由此可理解生产设计为何需要集中管理、隔离模式与清理钩子，而不是单一临时 helper。

读完后，可将每项生产特性对应到它所要防止的具体故障模式。

**八个步骤：**

1. 基础 Swoole Context（命名空间碰撞）
2. 用 `spl_object_id()` 处理多实例（解决碰撞）
3. 支持多属性（Context 类聚合状态）
4. 简化访问（`__get()` 魔术方法）
5. 自动发现（命名约定）
6. 处理继承（沿类层次向上查找）
7. 使用类型系统（反射读取返回类型）
8. 支持 FPM（检查 `Runtime::isCoroutineEnabled()`）

### 第 1 步：基础 Swoole Context {#step1}

`Coroutine::getContext()` 提供协程局部存储（`ArrayObject`）。它解决了基础隔离，但直接使用很快难以维护。

**简单用法：**

```php
class RequestState
{
    public function setAttribute(string $name, mixed $value): void
    {
        $context = \Swoole\Coroutine::getContext();
        $context[$name] = $value;
    }

    public function getAttribute(string $name): mixed
    {
        $context = \Swoole\Coroutine::getContext();
        return $context[$name] ?? null;
    }
}

// Request 1 (Coroutine #1): Gets its own context storage
// Request 2 (Coroutine #2): Gets a different context storage
// ✅ Isolated: Each coroutine has separate data
```

第一版仍有四个问题：

**问题 1：命名空间碰撞**

不同组件使用相同键名会互相覆盖数据：

```php
class RequestState
{
    public function setAttribute(string $name, mixed $value): void
    {
        $context = \Swoole\Coroutine::getContext();
        $context[$name] = $value;  // Uses string key
    }
}

class SessionState
{
    public function set(string $key, mixed $value): void
    {
        $context = \Swoole\Coroutine::getContext();
        $context[$key] = $value;  // Also uses string key - collision! ❌
    }
}

// Both components corrupt each other's data
$request = new RequestState();
$session = new SessionState();
$request->setAttribute('user_id', 123);  // Sets 'user_id'
$session->set('user_id', 456);           // Overwrites 'user_id'!
$request->getAttribute('user_id');       // Returns 456 instead of 123 ❌
```

**问题 2：多实例共享状态**

同一类的多个实例也会互相干扰：

```php
$request1 = new RequestState();
$request2 = new RequestState();
$request1->setAttribute('user_id', 123);  // Sets 'user_id'
$request2->setAttribute('user_id', 456);  // Overwrites same 'user_id'!
$request1->getAttribute('user_id');       // Returns 456 instead of 123 ❌
```

**问题 3：仅在 Swoole 下可用**

`Coroutine::getContext()` 在 FPM 中不存在，传统 PHP 环境会直接崩溃。

**问题 4：无类型安全**

字符串键无法提供 IDE 自动完成或类型检查——你不知道有哪些键、应存什么类型。

**根因：** 所有组件共享同一块扁平键值存储，没有任何隔离机制。

### 第 2 步：多实例与命名空间 {#step2}

第一版共享同一张扁平存储表。不同类会覆盖同一键，同一类的多个实例也会碰撞。

**解法：** 用每个对象的唯一 ID 作为键。

#### PHP 特性：`spl_object_id()` {#php-object-id}

PHP 内置函数为每个对象实例返回唯一整数：

```php
$obj1 = new stdClass();
$obj2 = new stdClass();

echo spl_object_id($obj1);  // e.g., 1
echo spl_object_id($obj2);  // e.g., 2

// Same object = same ID
$obj3 = $obj1;
echo spl_object_id($obj3);  // 1 (same as $obj1)
```

**关键性质：**

- **每实例唯一** — 每个存活对象得到不同 ID
- **稳定** — 对象存活期内 ID 不变
- **快速** — 原生 O(1) 查找
- **整数** — 高效的数组键
- **销毁后可复用** — 仅当与该 ID 绑定的存储随所有者生命周期一并清理时才安全

这样无需组件间手动协调即可自动命名空间隔离。

#### 实现 {#implementation}

```php
class RequestState
{
    public function setAttribute(string $name, mixed $value): void
    {
        $context = \Swoole\Coroutine::getContext();
        $oid = spl_object_id($this);  // e.g., 42
        $context[$oid][$name] = $value;
    }

    public function getAttribute(string $name): mixed
    {
        $context = \Swoole\Coroutine::getContext();
        $oid = spl_object_id($this);
        return $context[$oid][$name] ?? null;
    }
}

// Now works with multiple instances
$request1 = new RequestState();  // Object ID: 42
$request2 = new RequestState();  // Object ID: 87
$request1->setAttribute('user_id', 123); // $context[42]['user_id'] = 123
$request2->setAttribute('user_id', 456); // $context[87]['user_id'] = 456
```

同时解决两个问题：

**多实例正确工作** — 每个实例有唯一 object ID

**命名空间碰撞已解决** — 不同组件有不同 object ID

因每个组件实例写入各自的 object-ID 桶，命名空间不再碰撞：

```php
class RequestState
{
    public function setAttribute(string $name, mixed $value): void
    {
        $context = \Swoole\Coroutine::getContext();
        $oid = spl_object_id($this); // e.g., 42
        $context[$oid][$name] = $value;
    }
}

class SessionState
{
    public function set(string $key, mixed $value): void
    {
        $context = \Swoole\Coroutine::getContext();
        $oid = spl_object_id($this); // e.g., 87 (different!)
        $context[$oid][$key] = $value;
    }
}

// No collision - different object IDs
$request = new RequestState(); // Object ID: 42
$session = new SessionState(); // Object ID: 87
$request->setAttribute('user_id', 123); // $context[42]['user_id'] = 123
$session->set('user_id', 456);          // $context[87]['user_id'] = 456
$request->getAttribute('user_id');      // Returns 123 ✅ (reads $context[42])
```

**已解决问题：**

- 多实例正确工作
- 命名空间碰撞已解决

**仍存问题：**

- FPM 仍不可用
- 状态散落在代码各处（难以审计）
- 重复的 Context 访问样板
- **无法审计** — 一千行的类用了哪些 context 键？必须通读每一行才能找到所有 `setAttribute()` 调用

剩余问题是可发现性：状态键仍分散在各方法中。

### 第 3 步：支持多属性 {#step3}

实例隔离已可用，但状态定义仍分散。真实组件通常跟踪多个字段（用户、会话、属性），字符串键会掩盖这一模型。

解法是专用 Context 类，在一处声明状态。

```php
class RequestStateContext
{
    public ?User $user = null;
    public array $attributes = [];
    public string $sessionId = '';
}

class RequestState
{
    public function setUser(User $user): void
    {
        $context = \Swoole\Coroutine::getContext();
        $oid = spl_object_id($this);

        if (!isset($context[$oid])) {
            $context[$oid] = new RequestStateContext();
        }

        $context[$oid]->user = $user;
    }

    public function setAttribute(string $name, mixed $value): void
    {
        $context = \Swoole\Coroutine::getContext();
        $oid = spl_object_id($this);

        if (!isset($context[$oid])) {
            $context[$oid] = new RequestStateContext();
        }

        $context[$oid]->attributes[$name] = $value;
    }
}
```

**为何使用独立 Context 类？**

1. **类型安全** — 不再使用 `'user_id'`、`'session_id'` 等魔术字符串
2. **IDE 支持** — 自动完成可用，拼写错误在编译期可发现
3. **结构清晰** — 一眼看出组件需要哪些状态
4. **关注点分离** — Context（状态）与逻辑（方法）分离
5. **易于审计** — 所有状态在一处声明

对大类尤其有用：

```php
// ❌ String keys - must read entire class to find all state
class RequestState  // 1000 lines
{
    public function method1() {
        $this->context['user_id'] = ...;  // Line 50
    }

    public function method2() {
        $this->context['session_id'] = ...;  // Line 300
    }

    public function method3() {
        $this->context['attributes'] = ...;  // Line 800
    }

    // What state does this class use? You must read all 1000 lines!
}

// ✅ Context class - all state declared in one place
class RequestStateContext  // 5 lines
{
    public ?User $user = null;
    public array $attributes = [];
    public string $sessionId = '';
    // All state visible at a glance!
}

class RequestState  // 1000 lines
{
    // Business logic only, no state declarations scattered around
}
```

结合 Switon 标记，代码审查更系统化：

在 Swoole 中并非所有属性都危险。Switon 用标记简化审查：

- **`#[Autowired]`** — 依赖注入（通常是配置、无状态服务，或把可变状态放在 Context 对象中的服务）
- **`#[Communal]`**（`Switon\Core\Attribute\Communal`）— 审查标记：属性已确认为可在长驻服务实例上安全共享
- **Context 类** — 按作用域的状态（生产环境由 `ContextManager` 隔离）
- **无标记** — 在 Swoole 式部署中视为需要审查

> **关于 `#[Communal]` 标记，见 [Core 包](../../core/README.md#context-state)。**

Context 类是数据容器。公开属性即可，因为所有者组件控制访问。

**已解决问题：**

- 每实例多属性
- 类型安全（无魔术字符串）
- 更好的 IDE 支持
- **易于审计** — 所有状态在一处声明

**仍存问题：**

- FPM 仍不可用
- 重复的 Context 访问样板（每个方法都要相同 5 行）

### 第 4 步：简化 Context 访问 {#step4}

没有抽象时，每个方法都重复相同的 Context 访问样板。

解法是用 `__get()` 隐藏存储查找。

访问不存在的属性时会调用 `__get()`。可用它让 `$this->context` 自动返回正确的 Context 对象：

```php
class RequestStateContext
{
    public ?User $user = null;
    public array $attributes = [];
}

/**
 * @property-read RequestStateContext $context
 */
class RequestState
{
    public function __get(string $name)
    {
        if ($name === 'context') {
            $ctx = \Swoole\Coroutine::getContext();
            $oid = spl_object_id($this);

            if (!isset($ctx[$oid])) {
                $ctx[$oid] = new RequestStateContext();
            }

            return $ctx[$oid];
        }

        throw new \Exception("Property {$name} not found");
    }

    public function setUser(User $user): void
    {
        $this->context->user = $user;  // ✅ Clean!
    }

    public function setAttribute(string $name, mixed $value): void
    {
        $this->context->attributes[$name] = $value;  // ✅ Clean!
    }
}
```

流程：

1. 写 `$this->context` 时，PHP 发现没有 `$context` 属性
2. PHP 调用 `__get('context')`
3. `__get()` 从 Swoole 存储取回（或创建）Context 对象
4. 返回该对象
5. 可访问其属性：`$this->context->count`

`@property-read` 告诉 IDE `$context` 存在及其返回类型。

**优势：**

- 业务逻辑清晰可读
- 每个方法无样板
- 不会忘记 Context 访问代码
- IDE 理解 `$this->context` 的类型

**已解决问题：**

- 清晰简洁的 API
- Context 访问对业务逻辑隐藏
- 更少维护代码

**仍存问题：**

- FPM 仍不可用
- 每个类都要实现 `__get()`
- Context 类名硬编码

### 第 5 步：自动发现 Context 类 {#step5}

第 4 步仍硬编码 `new RequestStateContext()`，每个组件都要自定义接线。

下一步是命名约定：在类名后追加 `Context`。

```php
class RequestStateContext
{
    public ?User $user = null;
}

/**
 * @property-read RequestStateContext $context
 */
class RequestState
{
    public function __get(string $name)
    {
        if ($name === 'context') {
            $ctx = \Swoole\Coroutine::getContext();
            $oid = spl_object_id($this);

            if (!isset($ctx[$oid])) {
                // Auto-detect: ClassName + "Context"
                $contextClass = static::class . 'Context';

                if (!class_exists($contextClass)) {
                    throw new \Exception("Context class {$contextClass} not found");
                }

                $ctx[$oid] = new $contextClass();
            }

            return $ctx[$oid];
        }

        throw new \Exception("Property {$name} not found");
    }

    public function setUser(User $user): void
    {
        $this->context->user = $user;
    }
}

// Auto-resolved
$request = new RequestState();  // Auto-finds RequestStateContext
$request->setUser($user);
```

流程：

1. 访问 `$this->context` 时触发 `__get('context')`
2. `__get()` 构造 Context 类名：`RequestState` + `Context` = `RequestStateContext`
3. 检查类是否存在
4. 创建实例并存储
5. 后续访问返回同一实例

优点：

- 自动 — 无需手动配置
- 约定驱动 — 命名可预测
- 约定极简 — 追加 `Context`

局限：

- 不灵活 — Context 类必须遵循命名约定
- 无类型安全 — IDE 不知道确切类型
- 重构不友好 — 重命名会破坏约定
- 魔术字符串 — 依赖字符串拼接

**仍存问题：**

- FPM 仍不可用
- 继承处理不当
- 命名约定不灵活

### 第 6 步：处理继承 {#step6}

仅靠命名约定在继承下很脆弱。父类用法正常，子类在缺少派生 Context 类时会失败。

- 直接使用 `RequestState` 正常
- 使用 `ExtendedRequestState` 会因「类不存在」崩溃
- 子类无逻辑变更 — 仅继承
- 错误指向缺失的派生 Context 类

**问题：**

```php
class RequestState
{
    // Uses naming convention: RequestState + "Context" = RequestStateContext
}

class RequestStateContext
{
    public ?User $user = null;
}

// Parent usage
$request = new RequestState();
$request->setUser($user);

// Child usage can fail without a derived context class
class ExtendedRequestState extends RequestState
{
    // Naming convention looks for: ExtendedRequestState + "Context" = ExtendedRequestStateContext
    // But ExtendedRequestStateContext doesn't exist!
}

$extended = new ExtendedRequestState();
$extended->setUser($user);  // Fatal error: ExtendedRequestStateContext not found
```

实践中失败原因：

1. **父类正常、子类崩** — 简单继承后行为改变
2. **错误面间接** — 失败出现在 `__get()` 内部
3. **约定隐式** — 父类未明确声明这一要求

**解法：** 沿类层次向上查找，使用第一个存在的 Context 类。

```php
class RequestStateContext
{
    public ?User $user = null;
}

/**
 * @property-read RequestStateContext $context
 */
class RequestState
{
    public function __get(string $name)
    {
        if ($name === 'context') {
            $ctx = \Swoole\Coroutine::getContext();
            $oid = spl_object_id($this);

            if (!isset($ctx[$oid])) {
                // Search up the class hierarchy
                $class = static::class;
                $contextClass = null;

                do {
                    $candidate = $class . 'Context';
                    if (class_exists($candidate)) {
                        $contextClass = $candidate;
                        break;
                    }
                    $class = get_parent_class($class);
                } while ($class !== false);

                if ($contextClass === null) {
                    throw new \Exception("Context class not found for " . static::class);
                }

                $ctx[$oid] = new $contextClass();
            }

            return $ctx[$oid];
        }

        throw new \Exception("Property {$name} not found");
    }

    public function setUser(User $user): void
    {
        $this->context->user = $user;
    }
}

// Child class reuses parent context
class ExtendedRequestState extends RequestState
{
    // No need to create ExtendedRequestStateContext
    // Automatically finds and uses RequestStateContext
}

// Works
$extended = new ExtendedRequestState();
$extended->setUser($user);  // Uses RequestStateContext from parent
```

**查找如何工作：**

1. 实例化 `ExtendedRequestState`
2. 首次访问 `$this->context` 触发 `__get()`
3. 从 `ExtendedRequestState` 开始查找：
    - 尝试 `ExtendedRequestStateContext` — 不存在
    - 上溯父类：`get_parent_class('ExtendedRequestState')` = `'RequestState'`
4. 尝试 `RequestStateContext`，存在则使用
5. 创建并返回 `new RequestStateContext()`

子类需要额外状态时：

```php
class ExtendedRequestStateContext extends RequestStateContext
{
    public string $sessionId = '';  // Additional state
}

class ExtendedRequestState extends RequestState
{
    // Now ExtendedRequestStateContext exists, so it will be used
}
```

**已解决问题：**

- 继承正确工作
- 子类可复用父 Context
- 子类可用自有 Context 覆盖

**仍存问题：**

- FPM 仍不可用
- 仍依赖命名约定（不灵活、无类型安全）

### 第 7 步：用类型系统发现 Context {#step7}

命名约定仍隐式且僵硬。更好做法是通过方法返回类型显式声明。

用 `getContext()` 返回类型与反射发现 Context 类。

```php
class RequestStateContext
{
    public ?User $user = null;
}

/**
 * @property-read RequestStateContext $context
 */
class RequestState
{
    public function __get(string $name)
    {
        if ($name === 'context') {
            $ctx = \Swoole\Coroutine::getContext();
            $oid = spl_object_id($this);

            if (!isset($ctx[$oid])) {
                // Use reflection to read return type of getContext()
                $method = new \ReflectionMethod($this, 'getContext');
                $returnType = $method->getReturnType();

                if (!$returnType || $returnType->isBuiltin()) {
                    throw new \Exception("getContext() must have a class return type");
                }

                $contextClass = $returnType->getName();
                $ctx[$oid] = new $contextClass();
            }

            return $ctx[$oid];
        }

        throw new \Exception("Property {$name} not found");
    }

    // Declare return type - this is what reflection reads
    public function getContext(): RequestStateContext
    {
        return $this->context;
    }

    public function setUser(User $user): void
    {
        $this->getContext()->user = $user;
    }
}
```

流程：

1. 组件声明带返回类型的 `getContext()`：`RequestStateContext`
2. 调用 `$this->getContext()` 时访问 `$this->context`
3. `$this->context` 触发 `__get('context')`
4. `__get()` 用反射读取 `getContext()` 的返回类型
5. 创建该类的实例并存储
6. 返回 Context 实例

继承行为：

PHP 的 `ReflectionMethod` 会自动沿继承链查找：

```php
class ExtendedRequestState extends RequestState
{
    // No need to redeclare getContext()
    // Automatically uses parent's RequestStateContext
}
```

子类需要不同 Context 类型时：

```php
class ExtendedRequestStateContext extends RequestStateContext
{
    public string $sessionId = '';
}

class ExtendedRequestState extends RequestState
{
    // Override to use different context
    public function getContext(): ExtendedRequestStateContext
    {
        return $this->context;
    }
}
```

改进点：

- **类型安全** — 编译器检查返回类型类是否存在
- **IDE 支持** — 自动完成、跳转定义、重构均可用
- **灵活** — Context 类可任意命名（不必叫 `XxxContext`）
- **显式** — 关系在代码中声明
- **重构友好** — 重命名可被自动检测
- **继承友好** — 子类自动继承父 Context

已解决问题：

- 无需命名约定
- 类型安全且 IDE 友好
- 命名灵活
- 继承仍可用

仍存问题：

- FPM 仍不可用

### 第 8 步：同时支持 FPM 与 Swoole {#step8}

上一步仍假设协程 API 存在。FPM 需要不同存储后端。

> **说明：** Switon 的 `ContextManager` 按 `Runtime::isCoroutineEnabled()`（内核设置的启动标志）分支，而非仅按扩展是否加载；协程存储路径在该标志为 on 时假定存在真实协程上下文。

草图中：Swoole 活跃时用协程存储；否则在对象上为 FPM 存 Context。

#### 实现 {#step8-implementation}

在第 7 步代码上增加一次判断即可：

```php
class RequestState
{
    protected ?object $_context = null;  // FPM storage

    public function __get(string $name)
    {
        if ($name === 'context') {
            // Check whether coroutine storage should be used
            if (\Switon\Core\Runtime::isCoroutineEnabled()) {
                // Coroutine runtime: use coroutine context
                $ctx = \Swoole\Coroutine::getContext();
                $oid = spl_object_id($this);

                if (!isset($ctx[$oid])) {
                    $ctx[$oid] = $this->createContext();
                }

                return $ctx[$oid];
            }
            // FPM: Store directly on object
            else {
                if ($this->_context === null) {
                    $this->_context = $this->createContext();
                }
                return $this->_context;
            }
        }

        throw new \Exception("Property {$name} not found");
    }

    // createContext() and getContext() same as Step 7
    // ...
}
```

要点：保留同一访问器，按运行时模式切换存储后端。生产中由 `ContextManager` 与 `Runtime::isCoroutineEnabled()` 集中处理。

运行时行为：

协程运行时：

1. 运行时模式允许协程局部存储
2. 使用 `Coroutine::getContext()` 做每协程存储
3. 每个协程获得隔离的 Context

FPM 中：

1. `extension_loaded('swoole')` 返回 `false`
2. Context 直接存在 `$_context` 属性
3. 每请求在独立进程运行，属性天然隔离

带来的效果：

- **同一组件 API** 于两种环境
- **FPM 低开销** — 直接属性存储
- **自动隔离路径** — FPM 靠进程隔离，Swoole 靠协程隔离
- **无需每组件运行时接线**

已解决问题：

- FPM 与 Swoole 均可用
- FPM 零额外开销（直接属性访问）
- 自动运行时检测
- 无需配置

运行时兼容性已解决，但生产仍需要集中管控：

仍存问题：

- **样板代码** — 每个类都要实现 `__get()`、`getContext()` 与反射逻辑
- **无集中管理** — 无法为调试检视所有活跃 Context
- **无清理机制** — 长驻进程中 Context 对象可能泄漏内存
- **单一隔离策略** — 部分 Context 需要不同隔离模式怎么办？
- **无监控** — 无法跟踪 Context 创建、访问模式或性能
- **难测** — Context 逻辑与业务逻辑混杂
- **继承问题** — 子协程总是继承父 Context（并非总是期望）
- **连接作用域状态** — 部分状态需跨协程生命周期持久

示例问题：

```php
// Problem 1: Some state should not be inherited
class TransactionContext
{
    public string $transactionId;  // Shared across child coroutines: wrong transaction scope
}

// Problem 2: Can't debug context state
// How do you inspect all active contexts?
// How do you know which components have context?

// Problem 3: Boilerplate repeated everywhere
class RequestState { /* __get(), getContext(), createContext() */ }
class SessionState { /* __get(), getContext(), createContext() */ }
class CacheState { /* __get(), getContext(), createContext() */ }
// Same code copied 100 times!
```

演进说明了核心机制；生产实现加入框架级管理。

用法与架构见 [Context 包](../README.md)。

---

## 下一步 {#next}

- [Context 包](../README.md) — 公开用法、隔离模式与常见陷阱
- [设计说明](../design.md) — 稳定存储与生命周期行为
- [Context 内部说明](README.md) — 何时阅读本目录及可用笔记
