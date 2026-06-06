---
sidebar_label: 日志包
description: switon/log：PSR-3、自动分类、结构化 context、按分类级别的过滤与 Appender。
---

# 日志包 {#log-package}

`switon/log` 为 Switon 应用提供**唯一** PSR-3 Logger：自动分类、结构化上下文，以及**按分类**的级别过滤。若你希望在 HTTP、CLI、worker 与测试中共用**同一套**稳定日志约定，可选用它。默认模型**不包含** handler 树，也**不包含**按目的地的路由规则。

**关键特性：**

- **标准 Logger 契约：** 注入 `Psr\Log\LoggerInterface`，使用常规 PSR-3 方法。
- **自动分类：** 默认由**调用点**推导；`Switon\Core\Categorizable` 可覆盖。
- **结构化上下文：** `{key}` 占位符渲染进消息；其余键保留在 `extra`。
- **按分类过滤：** 一个全局级别与 `levels` 覆盖组合；**最长前缀**规则将调节范围限定到某分类前缀。
- **输出 Appender：** 内置文件、stdout、syslog、内存等输出。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+

```bash
composer require switon/log
```

`Psr\Log\LoggerInterface` 默认绑定到 `Switon\Logging\Logger`。默认绑定读取 `LOG_LEVEL`，回落为 `debug`，并将**通过过滤**的条目经文件 Appender 写出。

---

## 快速开始 {#quick-start}

注入 `Psr\Log\LoggerInterface`，使用常规 PSR-3 方法。

```php
namespace App\Service;

use Psr\Log\LoggerInterface;
use Switon\Core\Attribute\Autowired;

class UserService
{
    #[Autowired] protected LoggerInterface $logger;

    public function register(int $userId): void
    {
        $this->logger->info('User {user_id} registered', [
            'user_id' => $userId,
        ]);
    }
}
```

默认情况下，接受的条目写入 `@runtime/logger/{scene}.log`。`{scene}` 为当前**运行时场景**（来自框架场景管理器，例如 `http`、`cli` 或 `schedule`）——**每个场景一个日志文件**，不是每个请求一个文件。

---

## 核心概念 {#core-concepts}

顺序与管线一致：**分类与级别**，再**消息形态**，再**输出**。

### 分类 {#category}

**分类**是与点号分隔的标识，用于 `levels` 过滤；**不是**文件路径，也**不是**每行 channel 标签。

默认由**调用点**推导：取第一个带类的栈帧（跳过闭包），加上方法名，映射为稳定的点分 id。例如 `App\Service\UserService::register()` → `app.service.user.register`。若方法名以 `Action` 结尾，会先去掉该后缀（`registerAction` → `register`）。

覆盖：

- 传入实现 `Switon\Core\Categorizable` 的 `message` 参数可显式指定分类。
- 当设置了 `context['exception']` 时，分类取自**抛出位置**，而非记录日志的 `catch` 块。

### 日志流程 {#routing}

日志保持**一条**简单路径：

`log()` → 分类 → 级别过滤 → `LogEntry` → Appender

- `LOG_LEVEL` 在没有任何 `levels` 前缀与分类匹配时提供默认级别。
- 使用 `levels` 时，**最长匹配**的分类**前缀**设定该次调用的**阈值**。级别**比阈值更详细**的调用会被丢弃（例如阈值为 `info` 时看不到 `debug`）。
- `LoggerLogged` 监听器可在 Appender 运行前向 `LogEntry::extra` 追加字段；见 [FAQ](faq.md#logger-logged-boundaries)。

### 消息与上下文 {#message}

**模式：** 可读句子放在 `message`；每次调用的字段放在 `context`。

- 仅 `message` 也应可读。
- `{key}` 只用于属于句子的值。
- 与 `{key}` 占位符匹配的键插值进消息。
- 不匹配占位符的键作为结构化数据保留在 `extra`。
- `context['exception']` 渲染可抛出对象，并可携带 Switon 异常上下文。

这使句子对人可读，其余数据可供结构化输出。

```php
try {
    $this->createOrder($items);
} catch (\Throwable $e) {
    $this->logger->error('Order creation failed', ['exception' => $e]);
}
```

Logger 存储 `LogEntry`：已插值的键留在 `message`，其余上下文在 `extra`，`exception` 渲染进 `message` 而非 `extra`。对 `Switon\Core\Exception`，`getContext()` 并入 `extra`（调用方键在冲突时胜出）。

### 输出 {#output}

接受的条目按顺序送到**每一个**已配置 Appender。

- 内置覆盖文件、stdout、syslog、内存输出。
- Appender 相互独立，但 Logger **顺序**调用；若某个 Appender 抛错，后续将不再执行。
- 内置文件 Appender 将写失败报告到**单独通道**，而不是让请求失败（见 [FAQ](faq.md#file-write-failure)）。
- `FormatterInterface` 控制文本或 JSON 等输出格式。

---

## 配置 {#configuration}

先从一个目的地和一个全局级别开始。仅当应用的某一部分日志**过多或过少**时，再为 `levels` 增加按分类的覆盖。

在 `switon.yml` 中配置：

```yaml
# switon.yml
Psr\Log\LoggerInterface:
  level: info
  levels:
    app.service.payment: warning
```

### 选项发现 {#option-discovery}

执行：

```bash
bash bin/console config:options 'Psr\Log\LoggerInterface'
```

---

## 使用注意 {#keep-in-mind}

- 若看不到日志，先检查目的地与**有效级别**。
- 若只有部分消息出现，检查 `levels` 中**最长匹配**的前缀，包含 Logger 绑定上的默认值（运行 `config:options` 或查看合并后的配置，**不要**只看 `switon.yml`）。
- 在 `LoggerLogged` 监听器中添加请求元数据或关联 id，避免在每个调用点重复。

---

## 下一步 {#next}

- **排查行为与默认值：** [FAQ](faq.md#log-faq)
- **理解取舍：** [设计说明](design.md#design-rationale)
