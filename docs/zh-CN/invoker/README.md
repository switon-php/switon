---
sidebar_label: Invoker 包
description: switon/invoker：在 Di Invoker 上执行 Guard/Interceptor 与可调用边界解析。
---

# Invoker 包 {#invoker-package}

`switon/invoker` 在 DI invoker 之上执行带 guard、带 interceptor 的方法调用。适合同一 dispatch 步骤需要在 HTTP、CLI、RPC 或自定义传输之间共享 callable 执行、参数解析与方法级钩子时选用。**本包不从请求输入构造参数，也不负责传输解析。**

**关键特性：**

- **统一 callable 契约：** 数组 callable、闭包、字符串 callable、可调用对象共用同一入口。
- **显式覆盖优先：** 位置、命名、默认值与类型键覆盖优先于 DI 回退。
- **DI 支撑执行：** 底层仍由 `Switon\Di\InvokerInterface` 负责 callable 执行与参数解析。
- **方法级钩子：** `Switon\Invocation\Attribute\GuardInterface` 在受管调用前运行；`Switon\Invocation\Attribute\InterceptorInterface` 包裹调用。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+

```bash
composer require switon/invoker
```

`Switon\Invoking\InvokerInterface` 是带钩子的入口；钩子契约本身在 [Invocation 包](../invocation/README.md#invocation-package)。

## 快速开始 {#quick-start}

在调用点已经拿到目标 callable 与显式覆盖时，注入 `Switon\Invoking\InvokerInterface`，调用 `invoke()`。

```php
namespace App\Dispatch;

use Switon\Core\Attribute\Autowired;
use Switon\Core\AppInterface;
use Switon\Invoking\InvokerInterface;

class Dispatcher
{
    #[Autowired] protected InvokerInterface $invoker;

    public function dispatch(object $controller, string $action, int $id): mixed
    {
        return $this->invoker->invoke([$controller, $action], ['id' => $id]);
    }
}

class PostController
{
    public function showAction(int $id, AppInterface $app): array
    {
        return ['id' => $id, 'app' => $app->id()];
    }
}
```

调度器提供 callable 边界与显式 `id`；`AppInterface` 由 DI 回退解析。

若参数仍须从请求输入、类型化输入或 builder 构建，请先在 [Binding 包](../binding/README.md#binding-package) 完成。

---

## 核心概念 {#core-concepts}

### 可调用边界 {#callable-boundary}

支持常见 dispatch 边界形态：

- `[$controller, 'showAction']` 等数组 callable；
- 闭包、字符串 callable、可调用对象。

调用点已知道最终 callable 且要统一执行路径时用 `invoke()`。

### 参数解析 {#argument-resolution}

最终参数按显式顺序解析：

1. 显式位置覆盖  
2. 显式命名覆盖  
3. 默认值  
4. 类型键对象覆盖  
5. DI 回退（自动装配或容器查找）

可变参数优先接受命名或类型键数组；否则吸收尾部位置参数。

本步只解析**最终** callable 实参；输入构造归属 [Binding 包](../binding/README.md#binding-package)。

### Invocation 钩子 {#invocation-hooks}

钩子仅挂在**受管方法调用**上。

- guard 在方法体之前运行，经各自的 `handle()` 收到目标 `ReflectionMethod`
- interceptor 包裹受管调用：`preHandle()`、方法体、`postHandle()`；抛错时走 `onException()`
- `preHandle()` 返回 `false` **不会**跳过调用；要阻止请抛异常
- `postHandle()` 按引用接收返回值，可替换之
- `$service->run()` 等直接调用绕过上述两阶段
- **构造函数**与普通**函数** callable 只走 DI invoker，**不带**钩子

## 配置 {#configuration}

Invoker 配置很轻：`Switon\Invoking\InvokerInterface` 走标准 DI；本包**不**提供独立选项面。

需要不带钩子的底层解析与执行时，框架代码仍用 `Switon\Di\InvokerInterface`。

## 使用注意 {#keep-in-mind}

- DI 仍拥有自动装配、共享服务、命名槽与普通 callable 执行；invoker 只在其上增加钩子层。
- 直接方法调用不会触发 guard 或 interceptor。
- 若只有 `ReflectionMethod`，先凑出 callable 边界再调用 `invoke()`。

## 下一步 {#next}

- **在调用前构造实参：** [Binding 包](../binding/README.md#binding-package)
- **定义 guard 与 interceptor 契约：** [Invocation 包](../invocation/README.md#invocation-package)
- **常见问题：** [FAQ](faq.md#invoker-faq)
- **与 binding 的分工：** [设计说明](design.md#design-rationale)
