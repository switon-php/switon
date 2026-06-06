---
sidebar_label: Renderer 包
description: switon/renderer：Frames 事务、.phtml/.sword 引擎与 partial/section/stack。
---

# Renderer 包 {#renderer-package}

`switon/renderer` 通过共享的 **`Frames` 事务**运行模板。可作为底层模板层使用：PHP 与 Sword 引擎、主题查找与片段组合。本包**不**负责控制器路由或请求生命周期约定；那是 `switon/view` 的职责。

**关键特性：**

- **`Frames` 事务：** 一次 `render()` 返回共享状态、内容、sections、stacks 与 theme。
- **同一应用双引擎：** 原生 `.phtml` 与编译型 `.sword`，按文件扩展名选择。
- **模板组合：** `partial`、`section`、`stack` 与 `once` 经运行时对象完成。
- **主题感知查找：** 在 `Frames` 上设置主题后，带主题的 `View` 路径优先于默认模板。
- **生命周期钩子：** 提供渲染相关事件以便观测。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+、`switon/core`、`switon/event`、`switon/sync`、`psr/event-dispatcher`

```bash
composer require switon/renderer
```

---

## 快速开始 {#quick-start}

注入 `RendererInterface`，渲染模板，再从 `Frames` 读取内容。

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Rendering\RendererInterface;

final class WelcomePage
{
    #[Autowired] protected RendererInterface $renderer;

    public function render(array $user): string
    {
        $frames = $this->renderer->render('@app/View/home', [
            'title' => 'Welcome',
            'user' => $user,
        ]);

        return $frames->content();
    }
}
```

多次模板执行需要共享状态时，用 `Frames`。

```php
use Switon\Rendering\Frames;

$frames = Frames::of(['title' => 'Welcome'])->setTheme('dark');
$this->renderer->render('@app/View/page', [], $frames);
$this->renderer->render('@app/View/layout', [], $frames);
```

---

## 核心概念 {#core-concepts}

### 渲染事务 {#render-transaction}

`RendererInterface::render()` 返回 `Switon\Rendering\Frames` 对象，而非裸字符串。`RendererInterface::exists()` 在应用主题与引擎查找规则后检查模板名是否可解析。

`Frames` 承载输入变量、最终内容（`content()` 与 `__toString()`）、命名 sections、有序 stacks、事务级 once 标记以及当前 theme。渲染返回这个事务对象而不只是字符串，方便布局组合一次性读取这些信息。

### 模板运行时 {#template-runtime}

模板把当前事务接收为 `$__frames`。通过它调用 `partial()`、`section()`、`stack()`、`renderStack()` 与 `once()`。这些方法只在渲染事务活跃时可用；在渲染调用之外使用 `partial()` 会抛异常。

### 引擎 {#engines}

`Renderer` 按解析到的文件扩展名选择引擎：`.phtml` 走原生 PHP，`.sword` 先编译为 PHP 再执行。Sword 为类 Blade 的指令格式，适合希望有模板辅助又避免内联 PHP 的团队。引擎表经 DI 接线，应用可通过容器配置增删或替换扩展名。

### 模板路径 {#template-paths}

模板名可以是绝对别名（`@app/View/home`），或相对当前模板（`sidebar`）。当 `Frames` 携带 theme 时，会先在有主题的 `/View/` 路径查找，再回退默认。

### 预编译 {#precompile}

`bash bin/console sword:compile` 清空 `@runtime/sword`，然后编译在 `@app/View` 与 `@app/Areas/*/View` 下发现的 `.sword` 模板。`--replace` 将编译得到的 `.phtml` 写到源文件旁，而非运行时缓存。

---

## 使用注意 {#keep-in-mind}

- `__frames` 为保留名；用户变量以 `EXTR_SKIP` 方式 `extract`，冲突键会被丢弃而不会覆盖运行时变量。
- 若多次渲染须共享 sections、stacks 或 theme，请复用同一 `Frames`；需要隔离则使用新实例。
- `e()` 为全局 HTML 转义辅助函数。
- `switon/view` 拥有面向控制器的页面流；本包停留在模板层。

---

## 下一步 {#next}

- **常见问题：** [FAQ](faq.md#renderer-faq)
- **取舍与边界：** [设计说明](design.md#design-rationale)
- **控制器页面渲染：** [View 包](../view/README.md)
