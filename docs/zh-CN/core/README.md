---
sidebar_label: Core 包
description: switon/core：Autowired、App 元数据、Scene、Context 契约、路径与全局辅助。
---

# Core 包 {#core-package}

`switon/core` 提供整站共用的 `Switon\Core` **契约、属性与工具**，其它包都站在这一层之上。它统一框架词汇，但**不**吞业务域，也**不**决定启动顺序。

**关键特性：**

- **属性注入：** `#[Autowired]` 与 `Lazy` 走常规注入；`instances: true` 可把数组项解析成服务实例；
- **应用元数据：** `AppInterface` 读取 id、name、version、环境、调试开关与时区；
- **运行场景：** `SceneManagerInterface` 把 scene 与应用元数据分开；
- **Context 状态：** `ContextAware`、`ContextManagerInterface` 把可变请求/协程状态从共享服务里挪出；默认实现由 `switon/context` 提供；
- **共享辅助：** JSON、路径、字符串、命名、文件、随机、回溯等工具保持框架一致口径；
- **分类契约：** `Categorizable` / `Categorized` 给日志与路由稳定 category；
- **契约分层：** Core 主要发布接口与标记；具体实现多在兄弟包。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+、`ext-json`

```bash
composer require switon/core
```

---

## 快速开始 {#quick-start}

- 注入 `AppInterface`，由 `#[Autowired]` 填充；
- 应用级描述信息只通过 `AppInterface` 读；
- 仅当静态引导桥接不可避免时用 `App` 类。

```php
use Switon\Core\AppInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Json;

class StatusReporter
{
    #[Autowired] protected AppInterface $app;

    public function snapshot(): string
    {
        return Json::stringify([
            'app' => $this->app->name(),
            'env' => $this->app->env(),
            'debug' => $this->app->isDebug(),
        ], JSON_PRETTY_PRINT);
    }
}
```

---

## 核心概念 {#core-concepts}

### 自动装配 {#autowiring}

`#[Autowired]` 是属性注入标记，由 DI 包读取；Core 只声明标记。

- 在 `protected` 或 `public` 属性上注入服务。
- 依赖希望留在类型图里、首次使用时再解析时，用 `Lazy` 与联合类型（`FooInterface|Lazy`）；DI 会包成 `LazyPropertyProxy`。
- 对数组，`instances: true` 会把每一项解析成**服务实例**而不是纯配置值。
- 用 `new` 手建对象会跳过注入；若期望注入，请用容器或 `MakerInterface::make()`。

**分类契约：** `Categorizable` / `Categorized` 为日志、过滤或路由提供稳定 category。

### 应用元数据与 scene {#app-metadata-vs-scene}

`AppInterface` 与 `SceneManagerInterface` **刻意分开**。

- `AppInterface` 回答「这是哪个应用、如何部署」（id、name、version、环境、debug、时区）。当作只读元数据。
- `SceneManagerInterface` 回答「当前在执行哪种运行时」（`http`、`cli`、`schedule` …）。在引导入口上用 `#[Scene('cli')]` 等标记 scene。
- 可变的请求级或连接级状态属于 `ContextAware`，**不要**放在 `AppInterface` 上，也不要塞进共享服务字段。

### Context 状态 {#context-state}

`ContextAware` 与 `ContextIsolated` 标记应放在**每请求或每协程** context 对象里的属性。`ContextManagerInterface` 是契约；`switon/context` 提供默认实现。`ContextConnScoped` 描述连接生命周期数据；默认 manager **不会**据此分支。

`#[Communal]` 表示**有意跨 context 共享**的约定标记；Core 不会在运行时替你强制共享行为。

### 路径与运行时 {#paths-and-runtime}

- `Runtime::getRoot()` 解析项目根：先看 `SWITON_ROOT`，否则回退到 Composer `autoload.php` 位置。测试或引导里可用 `Runtime::setRoot()` 覆盖。
- `PathAliasInterface` 展开 `@root`、`@app`、`@runtime`、`@vendor`、`@resources` 以及通过 `#[ResourceAlias]` 注册的别名。

### 辅助类与异常 {#helpers-and-exceptions}

Core 自带小型工具类与少量全局辅助，二者保持框架一致口径，供各包复用。

- **类：** `Json`、`Strings`、`Naming`、`ClassName`、`Backtrace`、`Filesystem`、`Random`、`Clock`、`FilterMatcher`。
- **全局函数（随 Core 自动加载）：** `make()`、`env()`、`json()`、`console_log()`、`array_first()`、`array_last()`。
- **异常：** `Switon\Core\Exception::raise($template, $context)` 用 `{key}` 占位组装消息，并把 `$context` 存进异常供下游处理器使用。

### 静态引导桥 {#static-bootstrap-bridge}

`Switon\Core\App` 是**引导与兼容边界**用的静态容器桥。`App::get()` 取共享服务，`App::make()` 造新实例。框架已正常运行后，优先注入 `AppInterface`、`Switon\Core\ContainerInterface` 或 `Switon\Core\MakerInterface`。

应用**边界**仍由 `AppInterface`（元数据）与 `SceneManagerInterface`（运行时 scene）分担；**启动编排**在引导侧。**服务提供者**用于包级接线；日常应用配置仍在 `switon.yml` 与 DI。

**PHP 属性：** `#[Autowired]`、`#[Scene]`、`#[ResourceAlias]`、`#[Communal]` 等支撑统一应用模型，详见上文各节。

---

## 使用注意 {#keep-in-mind}

- 用 `new` 手建对象会跳过 `#[Autowired]`；
- 把 `AppInterface` 当**元数据**用，请求态别塞进去；
- 可变状态走 `ContextAware` + `ContextManagerInterface`，不要堆在共享服务单例上；
- 测试或引导需要改根目录时用 `Runtime::setRoot()`；
- `App::get()` 仅出现在引导边界；
- scene 不要并进 app 元数据，它属于运行时状态。

---

## 下一步 {#next}

- **常见问题：** [FAQ](faq.md#core-faq)
- **取舍与设计：** [设计说明](design.md#design-rationale)
- **启动顺序与 Provider 发现：** [Kernel 包](../kernel/README.md)
- **默认容器行为：** [DI 包](../di/README.md)
