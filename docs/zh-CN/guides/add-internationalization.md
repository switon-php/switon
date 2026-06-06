---
description: 任务优先路线：在一条已工作的页面或 API 路径上增加区域感知文案、请求级区域设置或持久化的语言偏好。
---

# 添加国际化 {#add-internationalization}

当某条已工作的页面或 API 路径现在需要区域感知的文案、请求作用域的区域设置选择，或持久化的语言偏好，且不希望把翻译平台、本地化路由或前端专属 i18n 栈当作第一答案时，使用本指南。

## 选择首个国际化边界 {#internationalization-boundary}

从与真实区域决策相匹配的最小边界开始。

| 边界 | 最适合 | 注意 |
|---|---|---|
| `TranslatorInterface::translate()` | 一个服务、页面或 API 响应现在需要区域感知字符串 | 当已有一条工作路径且只需改输出文案时，是最佳首步 |
| `LocaleInterface::set()` | 一条显式请求路径应立即切换区域 | 仅影响当前请求上下文；**不会**自行持久化到下次请求 |
| `RequestLocaleFilter` | 区域应跟随请求输入，如 query、cookie 或 `Accept-Language` | 检测顺序固定：query → cookie → header → default；在 `switon/http` 下，当存在 `LocaleInterface` 时过滤器会自动注册，即 HTTP 包会为你添加内置生命周期监听器 |
| `CookiesInterface::set()` | 用户的区域选择应延续到下次请求 | `RequestLocaleFilter` 稍后读取 cookie，但**不会**替你写入 |
| `translate(..., true)` 与 ICU | 复数或 select 逻辑才是真正的翻译问题 | 需要 `intl` 扩展；当普通 `{key}` 占位符足够时，不要把 ICU 当作第一步 |

一种常见应用形态：

- `@resources/i18n` 下一个默认区域文件
- 通过 `TranslatorInterface` 的一条已翻译服务或页面响应
- 对请求驱动选择的 HTTP 区域检测
- 仅在决定区域应跨请求保留之后，才写一次 cookie

## 推荐构建顺序 {#build-order}

### 1. 在一条页面或 API 路径已正确之后再开始 {#build-stable-path}

在你信任现有路由、控制器、服务与响应形态之后，国际化会容易得多。
不要用 i18n 作为路由、渲染或 JSON 契约问题的首要解释。

### 2. 先创建一个默认区域文件 {#build-default-locale}

在 `@resources/i18n` 下从一个真实默认区域（如 `en.php`）开始。
这样在区域或仅语言回退变得重要之前，翻译器有一个权威基线。

若候选区域文件都不存在，翻译会抛出 `LocaleFileNotFoundException`。
因此第一条 i18n 成功线应始终包含一个真实默认文件。

### 3. 在扩大范围之前先翻译一条服务拥有的消息 {#build-message}

选一条能用一句话解释的消息：

- 仪表盘标题
- 欢迎语
- 一条 API 状态消息
- 应用服务产生的一条可用于邮件的字符串

这样可证明翻译边界，而无需把第一次改动变成完整消息目录项目。

### 4. 让一个区域来源清晰胜出 {#build-source}

为第一条路径选择真实的区域来源：

- 应用默认单区域时使用配置默认区域
- 一条请求路径显式改语言时使用 `LocaleInterface::set()`
- 正常请求应由 HTTP 输入决定区域时使用 `RequestLocaleFilter`

在第一条流清晰之前，**不要**组合多个竞争的区域设置器。
若应用有全局路由前缀如 `/api`，保持任何区域切换端点映射无前缀，并用 `bash bin/console router:prefix` 确认真实公开 URL。

### 5. 仅在请求本地切换可用之后再持久化区域 {#build-persist-later}

先证明当前请求渲染正确区域。
再决定是否让下次请求记住该选择。

需要持久化时，显式做两步：

- 对当前请求调用 `LocaleInterface::set($locale)`
- 对下次请求调用 `CookiesInterface::set('locale', $locale, ...)`

### 6. 在增加工作流复杂度之前先加区域覆盖 {#build-regional-overrides}

当 `zh-cn` 应与 `zh` 略有不同时，让区域文件仅覆盖需要的键。
这是 Switon 固定回退最强的地方：

- `zh-cn.php`
- 然后 `zh.php`
- 然后配置的默认区域如 `en.php`

在添加数据库、翻译平台或内容工具之前，使用这种有序回退。

### 7. 仅当 ICU 与缺失键可观测性是真问题时再添加 {#build-advanced}

普通占位符覆盖许多首次集成。
仅当复数或 select 规则确实需要时才移到 ICU。
仅当内容缺口成为运维问题时，才观察 `Switon\I18n\Event\TranslationKeyMissing`。

### 8. 在路由本地化或前端本地化设计之前停下 {#build-stop}

第一个 i18n 成果通常很小：

- 一个稳定的区域来源
- 一个可用的翻译目录
- 仅当应用真正需要时才持久化一个 cookie

在一条页面或 API 路径感觉正确之前，**不需要**本地化路由树、前端框架包装或翻译管理平台。

## 最小接线 {#minimal-wiring}

仅用短代码展示区域文件、翻译调用、HTTP 区域检测与可选 cookie 持久化之间的接缝。
下文应用名仅为示例。稳定部分是 Switon 的 i18n 与 HTTP 面。

### 从扁平区域文件开始 {#wiring-locale-files}

在 `@resources/i18n` 中以扁平 PHP 映射保留首批翻译：

```php
<?php
// @resources/i18n/en.php
return [
    'dashboard.title' => 'Dashboard',
    'dashboard.welcome' => 'Hello, {name}!',
];
```

```php
<?php
// @resources/i18n/zh.php
return [
    'dashboard.title' => '仪表盘',
    'dashboard.welcome' => '你好，{name}！',
];
```

这对第一条成功路径已足够。
仅当该区域确实需要对少量键使用不同措辞时，再添加如 `zh-cn.php` 的区域文件。

### 通过一个服务边界翻译 {#wiring-translator}

从一个拥有消息组装的服务或页面数据加载器开始：

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Core\TranslatorInterface;

class DashboardTextService
{
    #[Autowired] protected TranslatorInterface $translator;

    public function greeting(string $name): array
    {
        return [
            'title' => $this->translator->translate('dashboard.title'),
            'welcome' => $this->translator->translate('dashboard.welcome', ['name' => $name]),
        ];
    }
}
```

这样翻译决策靠近应用拥有的输出组装，而不是散落在无关工具中。

### 仅当边界匹配时才让 HTTP 请求选择区域 {#wiring-http-locale}

若请求输入应驱动区域，一小段配置即可：

```yaml
# switon.yml
Switon\Core\TranslatorInterface:
  dirs:
    - '@resources/i18n'

Switon\Core\LocaleInterface:
  default: en

Switon\Http\Filter\RequestLocaleFilter:
  query: lang
  cookie: locale
  header: accept-language
  supported:
    - en
    - zh
    - zh-cn
  aliases:
    zh-hans: zh-cn
```

在 `switon/http` 下，当存在 `LocaleInterface` 时 `RequestLocaleFilter` 会自动注册。
若默认值已合适，往往完全不必配置过滤器键。

### 仅当下次请求应记住时才持久化区域偏好 {#wiring-persist-locale}

用应用端点或动作显式切换区域：

```php
namespace App\Controller;

use Switon\Core\Attribute\Autowired;
use Switon\Core\LocaleInterface;
use Switon\Http\CookiesInterface;
use Switon\Http\RequestInterface;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('')]
class LocaleController
{
    #[Autowired] protected LocaleInterface $locale;
    #[Autowired] protected CookiesInterface $cookies;

    #[GetMapping('/settings/locale')]
    public function updateAction(RequestInterface $request): array
    {
        $newLocale = (string) $request->get('lang', 'en');

        $this->locale->set($newLocale);
        $this->cookies->set('locale', $newLocale, 365 * 86400);

        return ['locale' => $this->locale->get()];
    }
}
```

此 `GET` 路由只是区域切换边界的最小示例。真实应用中，应使用与用户改偏好方式匹配的动词与 CSRF 姿态。

此处两个边界很重要：

- `LocaleInterface::set()` 立即改变**当前**请求
- `CookiesInterface::set()` 帮助**下次**请求选择相同区域

若应用添加全局前缀如 `/api`，公开路径变为 `/api/settings/locale`，尽管控制器映射仍是 `'/settings/locale'`。

若在 `RequestLocaleFilter` 中更改 cookie 键，请让控制器写入键与配置对齐。

### 仅对真正的复数或 select 规则使用 ICU {#wiring-icu}

普通占位符是最佳首个默认。
当复数或 select 逻辑才是真问题时，把一个键切换到 ICU 模式：

```php
$message = $translator->translate('items.count', ['count' => 3], true);
```

保持该步骤狭窄。
ICU 应解决真实的消息格式化问题，而不是成为每条首次翻译的默认。

## 初期应避免 {#pitfalls}

有意让第一条国际化路径保持狭窄。

过早避免这些：

- **从区域文件返回嵌套数组** — Switon 期望扁平的 `array<string, string>` 映射，而非树形翻译文件
- **假设过滤器会自动持久化区域** — `RequestLocaleFilter` 读取区域；**不会**替你写 cookie
- **在需要规范化请求侧区域输入时忘记 `supported` 或 `aliases`** — 协商可将输入约束到支持的区域，并映射如 `zh-hans -> zh-cn` 的变体，但仅在你配置这些键时
- **跳过默认区域文件** — 没有任何候选区域文件时，翻译会抛出 `LocaleFileNotFoundException`
- **对简单字符串直接上 ICU** — 普通 `{key}` 占位符是更好的首条路径
- **指望区域文件编辑在 worker 中热重载** — 长驻运行时在区域文件变更后需要重启或重载
- **先发明本地化路由或模板 helper** — 在更广的呈现惯例之前，先证明一条已翻译的页面或 API 路径

初学者在完成第一条国际化路径后应有这样的感受：

> 我知道区域来自哪里、译文存放在哪里、何时区域变更只留在请求内，以及何时值得加 cookie 持久化。

## 深入阅读 {#go-deeper}

- **在第一条 HTML 路径已能工作之后增加区域感知页面：** [构建 HTML 页面](build-html-pages.md#build-html-pages) · [View](../view/README.md)
- **保持区域感知的 API 输出小而显式：** [构建 API](build-an-api.md#build-an-api) · [HTTP](../http/README.md)
- **深入翻译文件、回退与 ICU：** [I18n](../i18n/README.md)
- **保持区域选择请求本地且运行时安全：** [Context](../context/README.md) · [I18n design rationale](../i18n/design.md)
- **正确持久化、删除或限定区域 cookie：** [Cookies](../http/cookies.md)
- **让校验错误使用同一区域边界：** [Validation](../validation/README.md)
- **观察内容缺口而不是让请求失败：** [Event system](../event/README.md) · [I18n FAQ](../i18n/faq.md)
- **在扩大消息目录之前测试区域驱动输出：** [为已有应用添加测试](add-testing-to-a-working-app.md#add-testing-to-a-working-app)
