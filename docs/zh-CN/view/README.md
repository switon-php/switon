---
sidebar_label: View 包
description: switon/view：ViewContext、Controller::Action、widget/block 与 ViewRenderer。
---

# View 包 {#view-package}

`switon/view` 基于请求作用域状态、控制器模板解析和 HTTP 桥接来渲染 HTML 页面。

**关键特性：**

- **`Controller::Action` 或直接路径** — 按约定或由显式模板路径渲染。
- **请求作用域状态** — 在 `ViewContext` 中保存 layout、vars 与 content。
- **页面复用辅助** — `widget()` 承载带逻辑的片段，`block()` 承载模板片段。
- **HTTP 桥** — `ViewRenderer` 将处理器返回值映射为 HTML 响应。
- **资源 URL** — `AssetInterface::get()` 解析公共资源 URL。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+，以及提供核心状态、事件、HTTP 集成与渲染的框架组件（`switon/core`、`switon/event`、`switon/http`、`switon/renderer`）。

```bash
composer require switon/view
```

`Switon\Viewing\ServiceProvider` 注册 `ViewRenderer`；若缺少别名，会把 `@view` 设为 `@app/View`。Composer `autoload.files` 辅助函数也让 `asset()` 成为 `AssetInterface::get()` 的全局快捷方式。

---

## 快速开始 {#quick-start}

常规 HTML 页面路径使用 `#[ViewMapping]`。动作返回视图变量，由视图层渲染页面。

```php
use Switon\Core\Attribute\Autowired;
use Switon\Routing\Attribute\ViewMapping;
use Switon\Viewing\ViewInterface;

final class UserController
{
    #[Autowired] protected ViewInterface $view;

    #[ViewMapping('/profile')]
    public function profileAction(): array
    {
        $this->view->setLayout('@view/Layouts/Default');

        return [
            'title' => 'Profile',
            'user' => ['name' => 'Mark'],
        ];
    }
}
```

`ViewRenderer` 会把返回的数组转成 HTML。若启用了 layout，最终响应就会包含布局。

---

## 核心概念 {#core-concepts}

### 渲染路径 {#rendering-paths}

`render()` 接受直接模板路径或 `Controller::Action` 目标。

- 共享或显式模板更适合直接路径；
- 仅属于某个控制器的页面模板更适合 `Controller::Action`；
- 若存在嵌套模板目录，解析器会优先使用；
- **仅** `index` 可在无嵌套目录时回退到扁平文件。

### 请求状态 {#request-state}

`ViewContext` 保存单次请求的可变状态：

- `layout`
- `vars`
- `content`

使用 `setVar()`、`setVars()`、`setLayout()`、`disableLayout()`、`setContent()` 与 `getContent()` 操作该状态。

- `layout` 初始为 `null`，表示在设置或禁用前，`render()` 会使用 `View` 默认布局；
- `hasVar()` 遵循 `isset()` 语义，故值为 `null` 的键视为不存在。

### Widget 与 Block {#widgets-and-blocks}

- `widget()` 解析 widget 类、运行它，并 echo 返回的 HTML，或渲染返回的 vars；
- `block()` 渲染可复用模板片段并 echo；
- 短 widget 名默认经 `App\Widget\{Name}Widget` 解析；
- 短 block 名解析为 `@view/Blocks/{Name}`；
- 已包含 `/` 的 `block()` 路径按原样传递。

### HTTP 桥接 {#http-bridge}

`ViewRenderer` 监听 `Switon\Http\Event\RequestRendering`。

- 数组返回值成为视图 vars；
- `null` 不改动预填 vars；
- 带 `toArray()` 的对象展开为 vars；
- 其它对象变为 `data`；
- 先合并预填 vars，再用动作返回值覆盖相同键；
- 若响应已有内容，视图桥接会跳过渲染；
- 渲染器写入 HTML 内容和 `Content-Type: text/html; charset=utf-8`；
- `VarsResolving` 与 `VarsResolved` 允许监听器在合并前后调整视图 vars。

### 资源 {#assets}

`AssetInterface::get()` 将路径转为公共资源 URL，模板与辅助函数中的 `asset()` 调用同一解析器。

- 已存在文件可附加短版本哈希；
- 查询串保持不变；
- 结果按路径缓存。

---

## 使用注意 {#keep-in-mind}

- `View` 拥有页面流程，**不**拥有模板语法。
- `RendererInterface` 仍负责模板执行。
- `widget()` 与 `block()` **输出**内容（echo）。
- `rewrite` 仅在提供前缀时影响 URL 修正。
- `ViewContext` 是请求局部状态，**不是**全局状态。

---

## 下一步 {#next}

- **快速答疑：** [FAQ](faq.md#view-faq)
- **设计取舍：** [设计说明](design.md#design-rationale)
- **模板引擎细节：** [Renderer 包](../renderer/README.md)
- **HTML 页面 walkthrough：** [构建 HTML 页面](../guides/build-html-pages.md#build-html-pages)
