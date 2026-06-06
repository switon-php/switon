---
sidebar_label: Binding 包
description: Switon binding 包：#[ResolvedBy]、方法入参解析、标量与对象的绑定路径、类型化输入。
---

# Binding 包 {#binding-package}

`switon/binding` 负责把**输入**、**容器状态**和**类型自身规则**编成**方法实参列表**；类型化请求体也在这一层完成填充与校验。

**关键特性：**

- **类型自持解析：** `#[ResolvedBy(...)]` 把「怎么造这个值」写在**具体类型**上，而不是每个参数重复一遍。
- **内置范例：** `Page` 是最小的完整示例类型。
- **动作方法入参：** `ArgumentsBinderInterface` 把单个 `ReflectionMethod` 解析成一维实参列表。
- **类型化输入：** `InputBinderInterface` 从数组负载填充强类型输入对象。
- **自解析值类型：** `ArgumentResolvable` 由值类型参与自身构造。

## 安装 {#installation}

**环境要求：** PHP 8.3+

```bash
composer require switon/binding
```

## 快速开始 {#quick-start}

### 绑定动作实参 {#bind-action-arguments}

注入 `ArgumentsBinderInterface` 解析出实参，再交给 `InvokerInterface` 调用。
下面故意用 `Switon\Orm\Page` 和 `switon/invoker` 标出「绑定」和「调用」的接缝；它们是**搭档包**，不是 `switon/binding` 的硬依赖。

```php
use ReflectionMethod;
use Switon\Binding\ArgumentsBinderInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Orm\Page;
use Switon\Invoking\InvokerInterface;

class ExampleController
{
    public function indexAction(Page $page): void
    {
    }
}

class Dispatcher
{
    #[Autowired] protected ArgumentsBinderInterface $argumentsBinder;
    #[Autowired] protected InvokerInterface $invoker;

    public function dispatch(object $controller, string $action): mixed
    {
        $method = new ReflectionMethod($controller, $action);
        $arguments = $this->argumentsBinder->resolve($method);

        return $this->invoker->invoke([$controller, $action], $arguments);
    }
}
```

- **Binding** 决定每个实参从哪来。
- **Invoker** 执行最终调用。
- **`Switon\Orm\Page`** 是文档里最直观的「类型自持解析」范例类型。

## 核心概念 {#core-concepts}

### `#[ResolvedBy]` {#resolvedby}

**同一个具体类型**总是同一种构造方式时用它。

- 属性打在**类**上，不要打在参数上。
- **`Switon\Orm\Page`** 是 Switon 文档里最小、却完整的一例。
- **`ArgumentResolvable`** 是同一条思路：规则归值类型自己拿。
- 需要照抄形状时，仿 `Page` 即可。下面用本地 `UserPage` 演示，避免和 ORM 里的实现缠在一起。

```php
use Switon\Binding\Attribute\ResolvedBy;
use Switon\Binding\ValueResolverInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\InputInterface;
use ReflectionParameter;

#[ResolvedBy(UserPageResolver::class)]
class UserPage
{
    public static function of(int $page, int $limit = 10): static
    {
        // 真实类体省略
    }
}

final class UserPageResolver implements ValueResolverInterface
{
    #[Autowired] protected InputInterface $input;

    public function resolve(ReflectionParameter $parameter, string $type): mixed
    {
        return UserPage::of(
            (int)$this->input->get('page', 1),
            (int)$this->input->get('size', 10),
        );
    }
}
```

解析器从输入读 `page`、`size`，生成值并执行自身的上限等约束。

### 容器取值 {#container-values}

带接口注解或其它未在前期步骤解析的对象，会继续走**容器**解析。

### 标量输入 {#scalar-input}

标量参数来自 `Switon\Core\InputInterface`。

- 先按**参数名完全一致**查找；
- 再尝试 **`snake_case`** 形式；
- 随后遵循当前输入源的常规读取规则；
- 都未命中时仍可落到**默认值**等后续逻辑。

### 类型化输入 {#typed-input}

需要把请求数据直接绑定到一个类时，使用 `#[RequestBody]`、`#[RequestQuery]` 或 `#[RequestData]`。

```php
use Switon\Http\Attribute\RequestBody;

#[RequestBody]
class CreateUserInput
{
    public string $name;
    public ?string $email = null;
}

class UserController
{
    public function createAction(CreateUserInput $input): array
    {
        return ['name' => $input->name, 'email' => $input->email];
    }
}
```

- 写操作常见默认是 **`#[RequestBody]`**。
- 仅在必须**明示数据来源边界**时用另外两个属性。

### 选项发现 {#option-discovery}

执行：

```bash
bash bin/console config:options 'Switon\Binding\ScalarResolverInterface'
bash bin/console config:options 'Switon\Binding\ObjectResolverInterface'
```

## 使用注意 {#keep-in-mind}

- `#[ResolvedBy]` 打在**类型**上，不要绑在单个参数声明上。
- 无匹配的类型自持规则时，**接口型参数**仍可能由**容器**提供。
- 标量查找顺序：**精确参数名 → snake_case → 默认值链**。
- Binding 的职责止于**实参列表**；真正 `invoke` 由 `InvokerInterface` 完成。

## 下一步 {#next}

- **绑完参数怎么调：** [Invoker 包](../invoker/README.md#invoker-package)
- **HTTP 负载进类型：** [HTTP 类型化绑定](../http/typed-input-binding.md#typed-input-binding)
- **常见问题：** [FAQ](faq.md#binding-faq)
- **设计说明：** [设计说明](design.md#design-rationale)
