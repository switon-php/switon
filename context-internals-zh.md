# Context 实现深度解析

**Switon 如何实现在 FPM 和 Swoole 中完全透明的请求隔离。**

> **基础用法请参考 [Context 教程](docs/en/core/context.md)。**

---

## 简介 {#introduction}

Context 管理是构建一个能在传统 PHP-FPM 和现代异步/协程环境（如 Swoole）中无缝工作的框架时，最关键也最具挑战性的方面之一。它是让一切"正常工作"的隐形基础设施。

**终极目标：** 写一次代码，在 FPM 和 Swoole 中完全相同地工作，无需任何修改、检查或特殊处理。

当你在 Switon 中注入 `RequestInterface`、`IdentityInterface` 或任何框架服务时，你永远不需要考虑是在 FPM 还是 Swoole 中运行。你永远不需要检查 `if (swoole_running())`。你永远不需要手动跟踪协程 ID。**这种透明性正是精心设计的 Context 管理系统的结果。**

本文档将带你从最简单的实现一步步走向 Switon 的生产级系统。你将看到：

- 为什么需要 Context（它解决的问题）
- 如何从零开始构建 Context 系统，一步一步
- 每一步会遇到什么问题以及如何解决
- **如何让同一份代码在 FPM 和 Swoole 中都能工作**
- Switon 实现背后的设计决策
- 性能影响和权衡

读完后，你不仅知道**如何**使用 Context，还会理解它**为什么**这样工作，以及 Switon 如何实现真正的运行时透明。

---

## 为什么 Context 很重要 {#why-context-matters}

### PHP-FPM 的世界

传统 PHP 应用运行在 PHP-FPM 中，每个 HTTP 请求由独立的进程处理。这种"无共享"架构有一个美妙的特性：**自动隔离**。

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

在 FPM 中，这段代码完全安全。每个请求运行在自己的进程中，拥有独立的内存空间。请求结束时，进程销毁（或回收），所有状态自动清理。一个请求不可能看到另一个请求的数据。

**这种简单性是 PHP 的超能力。** 开发者不需要考虑线程安全、锁或状态隔离。它就是能工作。

### Swoole 的挑战

Swoole 改变了一切。Swoole 不是每个请求一个进程，而是作为长驻进程运行，将数千个并发请求作为轻量级协程处理。这带来了巨大的性能优势：

- 无进程创建开销
- 持久连接（数据库、Redis 等）
- 真正的异步 I/O
- 能处理 10,000+ 并发连接

但它引入了一个根本问题：**共享内存**。

```php
// 同样的 RequestState 类

// 请求 1（协程 #1）：$request->setAttribute('user_id', 123);
// 请求 2（协程 #2）：$request->setAttribute('user_id', 456);
// 请求 1 读取：$request->getAttribute('user_id'); // 返回 456！❌
```

多个协程共享同一个 `RequestState` 实例，所以它们共享同一个 `$attributes` 数组。这会导致：

- **认证绕过** - 用户 A 以用户 B 的身份登录
- **支付错误** - 用户 A 的订单扣了用户 B 的卡
- **调试噩梦** - Bug 只在并发负载下出现，本地无法复现
- **内存泄漏** - 数据在数千个请求中累积，直到服务器崩溃
- **GDPR 违规** - 个人数据暴露给错误的用户，法律责任
- **业务逻辑失败** - 折扣码、权限、配额应用到错误的用户
- **审计追踪损坏** - 日志显示用户 A 执行了用户 B 的操作，合规问题

**这就是 Context 问题。** 我们如何在 Swoole 中实现自动状态隔离，就像 FPM 一样，而不需要开发者编写运行时特定的代码？

### 理想的解决方案

我们想要的 Context 系统应该：

- **透明** - 同一份代码在 FPM 和 Swoole 中都能工作，无需 `if (swoole_running())` 检查
- **自动** - 无需手动跟踪协程 ID 或传递 Context
- **安全** - 不可能意外地在请求间共享状态
- **快速** - 最小开销，无性能损失
- **灵活** - 支持不同的隔离策略（继承、隔离、连接作用域）
- **零配置** - 开箱即用，无需设置
- **开发者友好** - 清晰的 API，良好的 IDE 支持，易于调试
- **集中且可检查** - 所有 Context 状态聚合在专用类中，易于审计和调试

Switon 的 Context 系统实现了所有这些目标。让我们看看如何做到的。

---

## 概览 {#overview}

本文档采用渐进式的结构：

**第一部分：演进（步骤 1-8）**
- 从最简单的实现开始
- 识别问题
- 逐个解决
- **发现每个解决方案带来的新问题**
- 逐步构建完整解决方案

**这个过程展示了 Context 管理看似简单实则复杂。** 每个解决方案看起来都能工作，但仔细分析会发现新的边界情况和限制。这个迭代过程展示了为什么生产级 Context 系统需要精心设计和对 PHP 运行时行为的深入理解。

**第二部分：生产实现**
- Switon 的实际实现如何工作
- 高级特性（隔离模式、继承、清理）
- 与依赖注入的集成

**第三部分：深入探讨**
- 设计决策和权衡
- 性能分析
- 常见陷阱及如何避免

每一步都建立在前一步的基础上，不仅展示解决方案，还展示**为什么**需要这个解决方案以及它**引入了什么新挑战**。

---

## 根本问题 {#problem}

### FPM：天然隔离

在传统 PHP-FPM 中，每个请求运行在独立的进程中：

```php
class RequestState
{
    protected array $attributes = [];
    
    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }
}

// 请求 1（进程 A）：$request->attributes = ['user_id' => 123]
// 请求 2（进程 B）：$request->attributes = ['user_id' => 456]
// ✅ 无冲突：不同进程，不同内存
```

### Swoole：共享内存问题

在 Swoole 中，多个请求作为协程运行在同一个进程中：

```php
// 同样的 RequestState 类

// 请求 1（协程 #1）：$request->attributes = ['user_id' => 123]
// 请求 2（协程 #2）：$request->attributes = ['user_id' => 456]
// ❌ 请求 1 现在看到 user_id = 456（数据损坏！）
```

**挑战：** 如何在不改变应用代码的情况下隔离每个请求的状态？

---

## 演进：从简单到生产 {#evolution}

让我们一步步构建 Context 系统，随着问题的出现逐个解决。

**你将在这个过程中看到：**

每一步看起来都解决了问题……直到你仔细观察。然后你会发现边界情况、性能问题或需要下一步改进的限制。这是有意为之的 - 它展示了构建生产级 Context 系统并不简单。**乍看简单的东西，仔细分析后会显露出隐藏的复杂性。**

最后，你将能够理解为什么 Switon 的 Context 系统是这样设计的。每个特性都有其存在的理由，解决了从更简单方法中出现的真实问题。

**你会反复看到的模式：**
1. ✅ "这个解决方案有效！"
2. 🤔 "等等，这个边界情况怎么办？"
3. ❌ "哦不，在这种场景下会出问题……"
4. 💡 "让我们改进它……"
5. 重复

这种迭代改进是复杂系统的构建方式。让我们开始。

**8 个步骤：**
1. 基础 Swoole Context（命名空间冲突）
2. 用 `spl_object_id()` 处理多实例（解决冲突）
3. 支持多个属性（Context 类聚合状态）
4. 简化访问（`__get()` 魔术方法）
5. 自动检测（命名约定）
6. 处理继承（向上搜索类层次结构）
7. 使用类型系统（反射读取返回类型）
8. 支持 FPM（检查 `extension_loaded('swoole')`）

### 步骤 1：基础 Swoole Context {#step1}

Swoole 提供了 `Coroutine::getContext()` 作为底层解决方案 - 它返回一个对每个协程唯一的存储容器（ArrayObject）。虽然这解决了基本的隔离问题，但直接使用它既繁琐又容易出错。

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

// 请求 1（协程 #1）：获取自己的 Context 存储
// 请求 2（协程 #2）：获取不同的 Context 存储
// ✅ 隔离：每个协程有独立的数据
```

**这看起来很简单，但有严重问题：**

**问题 1：命名空间冲突**

不同组件使用相同的键名会互相破坏数据：

```php
class RequestState
{
    public function setAttribute(string $name, mixed $value): void
    {
        $context = \Swoole\Coroutine::getContext();
        $context[$name] = $value;  // 使用字符串键
    }
}

class SessionState
{
    public function set(string $key, mixed $value): void
    {
        $context = \Swoole\Coroutine::getContext();
        $context[$key] = $value;  // 也使用字符串键 - 冲突！❌
    }
}

// 两个组件互相破坏数据
$request = new RequestState();
$session = new SessionState();
$request->setAttribute('user_id', 123);  // 设置 'user_id'
$session->set('user_id', 456);           // 覆盖 'user_id'！
$request->getAttribute('user_id');       // 返回 456 而不是 123 ❌
```

**问题 2：多个实例共享状态**

即使是同一个类的多个实例也会互相干扰：

```php
$request1 = new RequestState();
$request2 = new RequestState();
$request1->setAttribute('user_id', 123);  // 设置 'user_id'
$request2->setAttribute('user_id', 456);  // 覆盖同一个 'user_id'！
$request1->getAttribute('user_id');       // 返回 456 而不是 123 ❌
```

**问题 3：只在 Swoole 中工作**

`Coroutine::getContext()` 在 FPM 中不存在，所以这段代码在传统 PHP 环境中会崩溃。

**问题 4：无类型安全**

字符串键没有 IDE 自动完成或类型检查 - 你不知道有哪些键可用或它们应该存储什么类型。

**根本原因：** 所有组件共享同一个扁平的键值存储，没有任何隔离机制。

### 步骤 2：处理多实例和命名空间 {#step2}

第一个实现有一个致命缺陷：所有组件共享同一个 Context 存储。如果两个不同的类都使用名为 `'count'` 的键，它们会互相覆盖数据。更糟糕的是，如果你创建同一个类的两个实例，它们会共享同一个计数器！

**解决方案：** 使用每个对象的唯一 ID 作为键。

#### PHP 特性：`spl_object_id()`

PHP 提供了一个内置函数，为每个对象实例返回一个唯一的整数：

```php
$obj1 = new stdClass();
$obj2 = new stdClass();

echo spl_object_id($obj1);  // 例如，1
echo spl_object_id($obj2);  // 例如，2

// 同一个对象 = 同一个 ID
$obj3 = $obj1;
echo spl_object_id($obj3);  // 1（与 $obj1 相同）
```

**关键特性：**
- **每个实例唯一** - 每个对象获得不同的 ID
- **稳定** - ID 在对象生命周期内不变
- **快速** - 原生 C 函数，O(1) 查找
- **整数** - 作为数组键高效
- **可重用** - 对象销毁后 ID 可能被重用（但这没问题，因为旧对象已经消失）

这为我们提供了自动命名空间，无需组件间的任何手动协调。

#### 实现

```php
class RequestState
{
    public function setAttribute(string $name, mixed $value): void
    {
        $context = \Swoole\Coroutine::getContext();
        $oid = spl_object_id($this);  // 例如，42
        $context[$oid][$name] = $value;
    }
    
    public function getAttribute(string $name): mixed
    {
        $context = \Swoole\Coroutine::getContext();
        $oid = spl_object_id($this);
        return $context[$oid][$name] ?? null;
    }
}

// 现在多个实例可以正常工作
$request1 = new RequestState();  // 对象 ID：42
$request2 = new RequestState();  // 对象 ID：87
$request1->setAttribute('user_id', 123); // $context[42]['user_id'] = 123
$request2->setAttribute('user_id', 456); // $context[87]['user_id'] = 456
```

**这同时解决了两个问题：**

✅ **多个实例正常工作** - 每个实例有唯一的对象 ID

✅ **命名空间冲突解决** - 不同组件有不同的对象 ID

这就是为什么命名空间冲突现在不可能发生：

```php
class RequestState
{
    public function setAttribute(string $name, mixed $value): void
    {
        $context = \Swoole\Coroutine::getContext();
        $oid = spl_object_id($this); // 例如，42
        $context[$oid][$name] = $value;
    }
}

class SessionState
{
    public function set(string $key, mixed $value): void
    {
        $context = \Swoole\Coroutine::getContext();
        $oid = spl_object_id($this); // 例如，87（不同！）
        $context[$oid][$key] = $value;
    }
}

// 无冲突 - 不同的对象 ID
$request = new RequestState(); // 对象 ID：42
$session = new SessionState(); // 对象 ID：87
$request->setAttribute('user_id', 123); // $context[42]['user_id'] = 123
$session->set('user_id', 456);          // $context[87]['user_id'] = 456
$request->getAttribute('user_id');      // 返回 123 ✅（读取 $context[42]）
```

**已解决的问题：**
- ✅ 多个实例正常工作
- ✅ 命名空间冲突解决

**剩余问题：**
- ❌ 仍然不能在 FPM 中工作
- ❌ 状态分散在代码各处（难以审计）
- ❌ 重复的 Context 访问代码
- ❌ **无法审计** - 在一个 1000 行的类中，你怎么知道它使用了哪些 Context 键？你必须阅读每一行才能找到所有 `setAttribute()` 调用

**等等，还有更多……** 即使我们解决了命名空间冲突，我们仍然无法轻松看到组件使用了什么状态。Context 键分散在代码各处。让我们接下来解决这个问题。

### 步骤 3：支持多个属性 {#step3}

现在我们可以隔离不同的组件实例，但状态分散在代码各处。真实的组件需要多个属性 - 请求状态可能跟踪用户、会话、属性等。使用字符串键，这些分散在许多方法调用中。

**更重要的是：** 使用分散在代码各处的字符串键，不阅读每一行就无法知道组件使用了什么状态。

**解决方案：** 创建一个专用的"Context 类"，在一个地方明确声明所有状态。

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

**为什么使用单独的 Context 类？**

1. **类型安全** - 不再有像 `'user_id'` 或 `'session_id'` 这样的魔术字符串
2. **IDE 支持** - 自动完成有效，编译时捕获拼写错误
3. **清晰的结构** - 一眼就能看出组件需要什么状态
4. **关注点分离** - Context（状态）与逻辑（方法）分离
5. **易于审计** - 所有状态在一个地方声明

**可审计性优势对大型类至关重要：**

```php
// ❌ 字符串键 - 必须阅读整个类才能找到所有状态
class RequestState  // 1000 行
{
    public function method1() {
        $this->context['user_id'] = ...;  // 第 50 行
    }
    
    public function method2() {
        $this->context['session_id'] = ...;  // 第 300 行
    }
    
    public function method3() {
        $this->context['attributes'] = ...;  // 第 800 行
    }
    
    // 这个类使用什么状态？你必须阅读所有 1000 行！
}

// ✅ Context 类 - 所有状态在一个地方声明
class RequestStateContext  // 5 行
{
    public ?User $user = null;
    public array $attributes = [];
    public string $sessionId = '';
    // 所有状态一目了然！
}

class RequestState  // 1000 行
{
    // 只有业务逻辑，没有分散的状态声明
}
```

**结合 Switon 的标记系统，代码审查变得系统化：**

在 Swoole 中，并非所有属性都是危险的。Switon 使用标记使代码审查更容易：

- **`#[Autowired]`** - 依赖注入（安全，因为它们注入配置、无状态服务或内部使用 Context 的服务）
- **`#[Communal]`** - 已审查并确认可安全共享
- **Context 类** - 每个请求的状态（自动隔离）
- **无标记** - 需要审查

这使得在代码审查期间很容易发现哪些属性仍需审查。

> **有关 `#[Communal]` 属性和代码审查工作流的详细信息，请参阅 [Context - Part 2: Communal](docs/en/core/context.md#communal-shared-state)。**

Context 类只是一个简单的数据容器。为了简单起见使用公共属性 - 不需要 getter/setter，因为 Context 只被其拥有的组件访问。

**已解决的问题：**
- ✅ 每个实例多个属性
- ✅ 类型安全（无魔术字符串）
- ✅ 更好的 IDE 支持
- ✅ **易于审计** - 所有状态在一个地方声明

**剩余问题：**
- ❌ 仍然不能在 FPM 中工作
- ❌ 重复的 Context 访问代码（每个方法需要相同的 5 行）

### 步骤 4：简化 Context 访问 {#step4}

每个需要 Context 的方法都必须编写相同的 5 行样板代码。这既繁琐又容易出错，而且使业务逻辑难以阅读。

**解决方案：** 使用 PHP 的 `__get()` 魔术方法隐藏复杂性。

当你访问一个不存在的属性时，会调用 `__get()` 方法。我们可以用它让 `$this->context` 自动返回正确的 Context 对象：

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
        $this->context->user = $user;  // ✅ 简洁！
    }
    
    public function setAttribute(string $name, mixed $value): void
    {
        $this->context->attributes[$name] = $value;  // ✅ 简洁！
    }
}
```

**工作原理：**

1. 当你写 `$this->context` 时，PHP 发现没有 `$context` 属性
2. PHP 调用 `__get('context')`
3. `__get()` 从 Swoole 的存储中检索（或创建）Context 对象
4. 返回 Context 对象
5. 然后你可以访问它的属性：`$this->context->count`

**`@property-read` 注解** 告诉你的 IDE `$context` 存在以及它的类型，即使它不是真正的属性。这为你提供了自动完成和类型检查。

**为什么这更好：**

- 业务逻辑清晰易读
- 每个方法中没有样板代码
- 不可能忘记 Context 访问代码
- IDE 理解 `$this->context` 的类型

**已解决的问题：**
- ✅ 简洁的 API
- ✅ Context 访问从业务逻辑中隐藏
- ✅ 更少的代码需要维护

**剩余问题：**
- ❌ 仍然不能在 FPM 中工作
- ❌ 每个类都需要实现 `__get()`
- ❌ Context 类名是硬编码的

### 步骤 5：自动检测 Context 类 {#step5}

我们在 `__get()` 方法中硬编码了 `new RequestStateContext()`。这意味着每个组件类都需要自己的 `__get()` 实现。我们需要一种方法来自动找出要使用哪个 Context 类。

**解决方案：** 使用命名约定 - 在类名后附加"Context"。

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
                // 自动检测：类名 + "Context"
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

// 自动工作！
$request = new RequestState();  // 自动找到 RequestStateContext
$request->setUser($user);
```

**工作原理：**

1. 当访问 `$this->context` 时，触发 `__get('context')`
2. `__get()` 构造 Context 类名：`RequestState` + `Context` = `RequestStateContext`
3. 检查类是否存在
4. 创建实例并存储
5. 未来的访问返回同一个实例

**优点：**
- ✅ 自动 - 无需手动配置
- ✅ 基于约定 - 可预测的命名
- ✅ 简单 - 只需附加"Context"

**这种方法的问题：**
- ❌ 不灵活 - Context 类必须遵循命名约定
- ❌ 无类型安全 - IDE 不知道确切的类型
- ❌ 不利于重构 - 重命名会破坏约定
- ❌ 魔术字符串 - 依赖字符串拼接

**剩余问题：**
- ❌ 仍然不能在 FPM 中工作
- ❌ 不能正确处理继承
- ❌ 命名约定不灵活

### 步骤 6：处理继承 {#step6}

命名约定在继承方面有一个严重的 bug。**直接使用父类工作正常，但使用子类会悄无声息地失败。** 这是一个调试噩梦，因为：

- ✅ 直接使用 `RequestState` 完美工作
- ❌ 使用 `ExtendedRequestState` 崩溃并显示"找不到类"
- 🤔 子类中没有代码更改 - 只是扩展了父类
- 😱 错误消息没有解释真正的问题

**问题：**

```php
class RequestState
{
    // 使用命名约定：RequestState + "Context" = RequestStateContext
}

class RequestStateContext
{
    public ?User $user = null;
}

// ✅ 使用父类工作正常
$request = new RequestState();
$request->setUser($user);  // 工作！✅

// ❌ 使用子类失败
class ExtendedRequestState extends RequestState
{
    // 命名约定查找：ExtendedRequestState + "Context" = ExtendedRequestStateContext
    // 但 ExtendedRequestStateContext 不存在！
}

$extended = new ExtendedRequestState();
$extended->setUser($user);  // 致命错误：找不到类 'ExtendedRequestStateContext' 💥
```

**为什么这如此令人沮丧：**

1. **父类工作，子类失败** - 你没有改变任何逻辑，只是扩展了类
2. **神秘的错误消息** - 说"找不到 ExtendedRequestStateContext"，但你从未打算创建那个类
3. **难以调试** - 错误发生在 `__get()` 深处，而不是你编写继承的地方
4. **破坏现有代码** - 添加子类会破坏之前工作的功能
5. **不明显** - 命名约定要求在父类中不可见

这正是那种浪费数小时调试时间的微妙 bug。

**解决方案：** 向上搜索类层次结构，找到第一个存在的 Context 类。

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
                // 向上搜索类层次结构
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

// ✅ 子类自动使用父类的 Context
class ExtendedRequestState extends RequestState
{
    // 无需创建 ExtendedRequestStateContext
    // 自动找到并使用 RequestStateContext
}

// 完美工作！
$extended = new ExtendedRequestState();
$extended->setUser($user);  // 使用父类的 RequestStateContext
```

**搜索如何工作：**

1. 实例化 `ExtendedRequestState`
2. 首次访问 `$this->context` 触发 `__get()`
3. 从 `ExtendedRequestState` 开始搜索：
   - 尝试 `ExtendedRequestStateContext` - 不存在
   - 移动到父类：`get_parent_class('ExtendedRequestState')` = `'RequestState'`
4. 尝试 `RequestStateContext` - 存在！使用它
5. 创建并返回 `new RequestStateContext()`

**子类何时会声明自己的 Context？**

有时子类需要额外的状态：

```php
class ExtendedRequestStateContext extends RequestStateContext
{
    public string $sessionId = '';  // 额外的状态
}

class ExtendedRequestState extends RequestState
{
    // 现在 ExtendedRequestStateContext 存在，所以会使用它
}
```

**已解决的问题：**
- ✅ 继承正常工作
- ✅ 子类可以重用父类的 Context
- ✅ 子类可以用自己的 Context 覆盖

**剩余问题：**
- ❌ 仍然不能在 FPM 中工作
- ❌ 仍然使用命名约定（不灵活，无类型安全）

### 步骤 7：使用类型系统发现 Context {#step7}

命名约定仍然不够灵活。我们需要为每个类声明 `XxxContext`。更好的解决方案是使用 PHP 的类型系统 - 通过方法返回类型声明 Context 类，然后使用反射读取它。

**解决方案：** 添加带返回类型的 `getContext()` 方法，使用反射发现 Context 类。

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
                // 使用反射读取 getContext() 的返回类型
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
    
    // 声明返回类型 - 这是反射读取的内容
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

**工作原理：**

1. 组件声明带返回类型的 `getContext()`：`RequestStateContext`
2. 当调用 `$this->getContext()` 时，它访问 `$this->context`
3. `$this->context` 触发 `__get('context')`
4. `__get()` 使用反射读取 `getContext()` 的返回类型
5. 创建该类的实例并存储
6. 返回 Context 实例

**额外好处：继承仍然有效！**

PHP 的 `ReflectionMethod` 自动向上搜索继承链：

```php
class ExtendedRequestState extends RequestState
{
    // 无需重新声明 getContext()
    // 自动使用父类的 RequestStateContext
}
```

**子类何时会声明自己的 Context？**

```php
class ExtendedRequestStateContext extends RequestStateContext
{
    public string $sessionId = '';
}

class ExtendedRequestState extends RequestState
{
    // 覆盖以使用不同的 Context
    public function getContext(): ExtendedRequestStateContext
    {
        return $this->context;
    }
}
```

**我们获得了什么：**

- **类型安全** - 编译器检查返回类型是否存在
- **IDE 支持** - 自动完成、跳转到定义、重构都能工作
- **灵活** - Context 类可以有任何名称（不只是 `XxxContext`）
- **明确** - 关系在代码中声明
- **利于重构** - 重命名检测自动工作
- **继承友好** - 子类自动继承父类的 Context

**已解决的问题：**
- ✅ 不需要命名约定
- ✅ 类型安全且 IDE 友好
- ✅ 灵活的命名
- ✅ 继承仍然有效

**剩余问题：**
- ❌ 仍然不能在 FPM 中工作

### 步骤 8：同时支持 FPM 和 Swoole {#step8}

我们已经构建了一个在 Swoole 中工作的 Context 系统，但它在 FPM 中会失败，因为那里不存在 Swoole 扩展。

> **注意：** 为简单起见，我们假设如果 Swoole 扩展存在，我们就在协程模式中。生产代码还会检查 `Coroutine::getCid() > 0`。

**解决方案：** 在使用 Swoole 之前检查是否加载了 Swoole 扩展。

#### 实现

只需在步骤 7 的代码中添加一个检查：

```php
class RequestState
{
    protected ?object $_context = null;  // FPM 存储
    
    public function __get(string $name)
    {
        if ($name === 'context') {
            // 检查 Swoole 扩展是否存在
            if (extension_loaded('swoole')) {
                // Swoole：使用协程 Context
                $ctx = \Swoole\Coroutine::getContext();
                $oid = spl_object_id($this);
                
                if (!isset($ctx[$oid])) {
                    $ctx[$oid] = $this->createContext();
                }
                
                return $ctx[$oid];
            }
            // FPM：直接存储在对象上
            else {
                if ($this->_context === null) {
                    $this->_context = $this->createContext();
                }
                return $this->_context;
            }
        }
        
        throw new \Exception("Property {$name} not found");
    }
    
    // createContext() 和 getContext() 与步骤 7 相同
    // ...
}
```

**关键变化：** 只有一行 - `if (extension_loaded('swoole'))`

**工作原理：**

**在 Swoole 中：**
1. `extension_loaded('swoole')` 返回 `true`
2. 使用 `Coroutine::getContext()` 进行每协程存储
3. 每个协程获得隔离的 Context

**在 FPM 中：**
1. `extension_loaded('swoole')` 返回 `false`
2. 直接将 Context 存储在 `$_context` 属性中
3. 每个请求运行在独立的进程中，所以属性自然隔离

**为什么这很巧妙：**

- **相同的代码** 在两种环境中都能工作
- **FPM 中零开销** - 只是属性访问
- **自动隔离** - FPM 使用进程隔离，Swoole 使用协程隔离
- **无需配置** - 运行时检测是自动的

**已解决的问题：**
- ✅ 在 FPM 和 Swoole 中都能工作！
- ✅ FPM 中零开销（直接属性访问）
- ✅ 自动运行时检测
- ✅ 无需配置

**这看起来很完美……但等等！**

我们已经解决了核心隔离问题，但生产使用仍有许多问题：

**剩余问题：**
- ❌ **样板代码** - 每个类都需要实现 `__get()`、`getContext()` 和反射逻辑
- ❌ **无集中管理** - 无法检查所有活动 Context 以进行调试
- ❌ **无清理机制** - Context 对象可能在长时间运行的进程中泄漏内存
- ❌ **单一隔离策略** - 如果某些 Context 需要不同的隔离模式怎么办？
- ❌ **无监控** - 无法跟踪 Context 创建、访问模式或性能
- ❌ **难以测试** - Context 逻辑与业务逻辑混合
- ❌ **继承问题** - 子协程总是继承父 Context（并不总是期望的）
- ❌ **连接作用域状态** - 某些状态需要在协程生命周期中持久化

**示例问题：**

```php
// 问题 1：某些状态不能被继承
class TransactionContext
{
    public string $transactionId;  // ❌ 在子协程间共享 = 错误的事务范围！
}

// 问题 2：无法调试 Context 状态
// 如何检查所有活动的 Context？
// 如何知道哪些组件有 Context？

// 问题 3：样板代码到处重复
class RequestState { /* __get(), getContext(), createContext() */ }
class SessionState { /* __get(), getContext(), createContext() */ }
class CacheState { /* __get(), getContext(), createContext() */ }
// 相同的代码复制了 100 次！
```

**演进向我们展示了核心机制，但生产需要更多。**

**下一步：** 参见 [Context 设计](../context-design.md) 了解 Switon 的生产系统如何构建。

---

## 总结 {#summary}

**8 步演进：**

1. **基础 Swoole Context** - 使用 `Coroutine::getContext()` 但有命名空间冲突
2. **`spl_object_id()`** - 解决了冲突和多实例问题
3. **Context 类** - 在专用类中聚合状态以便审计
4. **`__get()` 魔术** - 用 `$this->context` 简化访问
5. **命名约定** - 通过附加"Context"自动检测 Context 类
6. **继承支持** - 向上搜索类层次结构查找 Context 类
7. **类型系统** - 使用反射读取 `getContext()` 返回类型
8. **FPM 兼容性** - 添加 `extension_loaded('swoole')` 运行时检测

**关键见解：**

1. **`spl_object_id()`** - 实现每实例 Context 和自动命名空间
2. **Context 类** - 聚合状态以便审计（vs 分散的字符串键）
3. **返回类型反射** - 通过类型系统自动发现 Context 类
4. **运行时检测** - 相同的代码在 FPM 和 Swoole 中都能工作
5. **迭代改进** - 每个解决方案都揭示了需要解决的新问题

**我们学到了什么：**

Context 管理看起来简单，但实际上非常复杂。每个解决方案看起来都很完美，直到仔细分析揭示了边界情况。这个迭代过程展示了为什么生产级 Context 系统需要：

- 集中管理（而不是分散在每个类中）
- 多种隔离策略（继承、隔离、连接作用域）
- 自动清理（防止内存泄漏）
- 事件系统（可观察性）
- 全面的测试

**演进展示了"为什么" - 现在看看"如何"：**

本文档向你展示了**为什么**需要 Context 以及**如何**从零开始构建它。你现在理解了核心机制和它解决的问题。

**下一步是什么？**

1. **想了解 Switon 的设计？**  
   → 阅读 [Context 设计](../context-design.md) - 契约、隔离策略和设计原则

2. **想在代码中使用 Context？**  
   → 阅读 [Context 教程](../context.md) - 带示例的实用指南

3. **想标记共享属性？**  
   → 阅读 [Context - Part 2: Communal](docs/en/core/context.md#communal-shared-state) - Swoole 安全的代码审查工作流

**推荐阅读顺序：**
- ✅ 你刚完成：Context 演进（本文档）
- → 下一步：[Context 设计](../context-design.md) - 查看 Switon 的契约和策略
- → 然后：[Context 教程](../context.md) - 学习如何在代码中使用

---

## 另请参阅 {#see-also}

- **[Context 设计](../context-design.md)** - Switon 的契约和策略
- [Context 教程](../context.md) - 基础用法指南和标记共享属性
- [Swoole 协程文档](https://wiki.swoole.com/zh-cn/#/coroutine) - Swoole 的协程系统
