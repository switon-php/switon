---
sidebar_label: Kernel 包
description: switon/kernel：env、配置、Provider 引导与 FatalErrorOccurred。
---

# Kernel 包 {#kernel-package}

`switon/kernel` 为 Switon 应用提供**单一引导契约**：加载环境、加载配置、引导 Service Provider、处理致命错误。适用于需要**可预测启动边界**的应用；Kernel **本身不**运行 HTTP、CLI 或 worker 主循环。

**关键特性：**

- **统一 start 契约：** `KernelInterface::start()` 在交还运行时控制权之前完成引导。
- **env 先于 config：** `.env.local` 与 `.env` 在 YAML 插值与 `config/*.php` 之前加载。
- **配置合并面：** 根目录 `switon.yml`（或 `SWITON_CONFIG_FILE`）加上可选的 `config/*.php` 补丁。
- **Provider 引导：** 从 Composer extra 元数据发现 Provider，注册、自动装配并 boot，顺序唯一。
- **致命 shutdown 事件：** 致命错误可派发为 `Switon\Kernel\Event\FatalErrorOccurred`。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+、`switon/core`、`switon/di`、`switon/event`、`switon/yaml`

Provider 发现还需要 `switon/composer-extra`，或显式绑定 `Switon\ComposerExtra\ComposerExtraInterface`。

```bash
composer require switon/kernel
```

---

## 快速开始 {#quick-start}

继承 `Switon\Kernel\Kernel`，调用 `parent::start()`，再将控制权交给你的运行时。

```php
use Switon\Kernel\Kernel;

class AppKernel extends Kernel
{
    public function start(): void
    {
        parent::start();

        // 在此启动 HTTP、CLI、worker 或自定义运行时
    }
}

(new AppKernel(__DIR__))->start();
```

`parent::start()` 会加载 env、创建容器、加载配置、引导 Provider、应用时区并注册 fatal 处理。

若根目录检测找不到 `vendor/autoload.php`，请向构造函数**显式传入**项目根。

HTTP 与 CLI 应用可继承各运行时自带的 Kernel（`Switon\Http\Kernel` 或 `Switon\Cli\Kernel`）；这些子类调用 `parent::start()` 后进入各自的运行时循环。

---

## 核心概念 {#core-concepts}

### Start 契约 {#start-contract}

`KernelInterface::start()` 是公开的引导边界。基类 Kernel 按**固定顺序**完成初始化；子类负责 `parent::start()` **之后**的一切。

### 环境加载 {#env-loading}

`Switon\Kernel\Env` 按优先级加载两个文件：

- 同键时 **`.env.local` 覆盖 `.env`**
- **系统环境变量**覆盖上述两者

变量插值**严格**：`$VAR` 与 `${VAR}` 引用必须已存在于系统 env，或在**更早**加载的文件中定义。缺失引用抛出 `EnvVariableNotFoundException`。

### 配置加载 {#configuration-loading}

`Switon\Kernel\Config` 加载根 YAML 与可见的 `config/*.php`，合并为一份服务定义映射。

- `switon.yml` 为基配置；`SWITON_CONFIG_FILE` 可指向其他 YAML 文件（`@root/...` 为相对项目根的便捷前缀）
- `config/*.php` 作为叠在 YAML 之上的 PHP 补丁，用于仅 PHP 的值或预建实例
- YAML 与 `config/` **均可缺省**；缺失则贡献为空
- 关联数组**递归合并**；序列（列表）**整体替换**

### Service Provider {#service-providers}

Provider 在同一引导流中执行：从 `composer.json` extra（`switon.providers`）发现 → 注册 → 注册用户配置 → 自动装配 → boot。boot 之后，若已绑定事件分发器，会派发 `Switon\Kernel\Event\ServiceBootstrapped`。

针对具体 Kernel 的 `<YourKernel>::class => ['services' => [...]]` 会在 Provider boot **之前**并入配置，并在**重复的顶层服务 ID**上胜出。

在 Kernel 类上的 `#[Scene]` 在配置注册之后、Provider boot 之前应用。

### Fatal 处理 {#fatal-handling}

引导完成后 Kernel 注册 fatal handler。若可用事件分发器，shutdown 阶段的致命错误可派发为 `Switon\Kernel\Event\FatalErrorOccurred`。

协程模式**仅**在：运行时为 **CLI**、已加载 **Swoole** 扩展、且 **未** 启用 Xdebug 时开启。

---

## 配置 {#configuration}

引导配置集中在根 YAML；仅当 PHP 更合适时使用 `config/*.php`。

```yaml
# switon.yml
Switon\Core\AppInterface:
  id: myapp
  name: My Application
  env: ${APP_ENV:-prod}
  debug: false
  timezone: ${APP_TIMEZONE:-UTC}

Switon\Kernel\FatalHandlerInterface:
  class: Switon\Kernel\FatalHandler
```

---

## 使用注意 {#keep-in-mind}

- Kernel **协调**启动；**不会**独自运行 HTTP、CLI 或 worker 循环。
- 根目录自动检测仅在已 `include` `vendor/autoload.php` 时有效。
- `config/*.php` 可选，点文件会被跳过。
- Fatal 处理在引导**之后**才开始，`start()` 尚未执行到该阶段前的失败**不在** handler 范围内。
- Kernel 专属的 `services` 在 Provider boot 前合并，可覆盖重复的顶层 ID。

---

## 下一步 {#next}

- **短答：** [FAQ](faq.md#kernel-faq)
- **取舍与设计：** [设计说明](design.md#design-rationale)
