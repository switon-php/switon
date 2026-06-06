---
sidebar_label: Invocation 包
description: switon/invocation：Guard/Interceptor 契约；运行时由 Invoker 执行。
---

# Invocation 包 {#invocation-package}

`switon/invocation` 定义方法级 **Guard** 与 **Interceptor** 钩子契约。

本包只提供属性与接口；通过 `Switon\Invoking\InvokerInterface` 调用时，由 **[Invoker 包](../invoker/README.md)** 在运行时执行这些钩子。

**关键特性：**

- **基于属性：** 用 PHP 属性把 guard / interceptor 挂在方法上；
- **执行前检查：** guard 在受管方法体之前运行；
- **环绕调用：** interceptor 包裹受管调用（之前、之后或异常时）；
- **共享基类：** `Switon\Invocation\Attribute\Interceptor` 覆盖常见「只实现部分生命周期」场景。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+

```bash
composer require switon/invocation
```

---

## 快速开始 {#quick-start}

Guard 可接收当前 `ReflectionMethod` 及构造函数声明的依赖；在 `handle()` 中**抛异常**即可阻止执行。

```php
use Attribute;
use ReflectionMethod;
use Switon\Invocation\Attribute\GuardInterface;
use Switon\Http\Exception\ForbiddenException;
use Switon\Http\RequestInterface;

#[Attribute(Attribute::TARGET_METHOD)]
final class IpGuard implements GuardInterface
{
    public function __construct(
        private array $ips,
    ) {
    }

    public function handle(RequestInterface $request, ReflectionMethod $method): void
    {
        if (!in_array($request->ip(), $this->ips, true)) {
            throw ForbiddenException::of('Access denied.');
        }
    }
}

final class AccountService
{
    #[IpGuard(['127.0.0.1'])]
    public function deleteAction(int $id): void
    {
    }
}
```

---

## 核心概念 {#core-concepts}

### Guard {#guards}

`GuardInterface` 标记方法级 guard 属性：

- 在属性类上实现公共 `handle(...)`；
- 启用 invocation 管道时，在 **interceptor 与目标方法之前** 运行；
- 在 `handle()` 中抛异常以阻止调用。

### Interceptor {#interceptors}

`InterceptorInterface` 定义环绕生命周期：

- `preHandle(ReflectionMethod $method): bool` 在方法体之前运行；抛异常可阻止。
- `postHandle(ReflectionMethod $method, mixed &$return): void` 在成功返回后运行，可通过引用替换返回值。
- `onException(ReflectionMethod $method, \Throwable $exception): void` 在目标抛错时运行。

`preHandle()` 返回 `false` **不会**跳过调用；要阻止请抛异常。只需其中部分钩子时，扩展 `Switon\Invocation\Attribute\Interceptor` 即可。

### 运行时边界 {#runtime-boundary}

本包**仅定义契约**：

- 钩子经 [Invoker 包](../invoker/README.md) 执行；像 `$service->deleteAction(1)` 这类直接调用会绕过 guard 与 interceptor。
- 从 HTTP、CLI 或 builder 构造参数属于 [Binding 包](../binding/README.md)，不是本包。

---

## 使用注意 {#keep-in-mind}

- Guard 与 interceptor 用 PHP **方法**属性挂载，而不是整类挂载。
- 钩子仅在 invoker 管理的调用上生效。
- 属性构造参数来自属性书写处；guard 的 `handle()` 仍可声明可由 invoker 解析的类型依赖（例如 `Switon\Http\RequestInterface`）。

---

## 下一步 {#next}

- **统一入口执行带钩子方法：** [Invoker 包](../invoker/README.md#invoker-package)
- **在 `invoke()` 前解析参数：** [Binding 包](../binding/README.md#binding-package)
