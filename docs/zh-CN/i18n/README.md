---
sidebar_label: I18n 包
description: switon/i18n：请求级 locale、扁平翻译文件、回退链与可选 ICU。
---

# I18n 包 {#i18n-package}

`switon/i18n` 为 Switon 应用提供**请求级**语言环境、**扁平**翻译文件、可配置回退查找，以及可选 **ICU** 格式化。

**关键特性：**

- **请求级 locale：** `Switon\Core\LocaleInterface` 隔离每个请求的语言；
- **扁平语言文件：** `@resources/i18n` 下简单 PHP 映射；
- **回退查找：** 单键可经配置 locale、语言级、默认 locale 逐级回退；
- **可选 ICU：** 仅需复数/选择规则时启用 `useICU`；
- **缺键信号：** 缺键发出 `TranslationKeyMissing`；
- **HTTP 输入：** 配合 `switon/http` 时可用 `RequestLocaleFilter` 从请求设定 locale。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+、`switon/core`、`switon/event`

需要**从请求选语言**时加 `switon/http`；需要 **ICU** 时加 `ext-intl`。

```bash
composer require switon/i18n
```

包 Provider 将 `LocaleInterface` 绑定到 `Switon\I18n\Locale`，`TranslatorInterface` 绑定到 `Switon\I18n\Translator`。

---

## 快速开始 {#quick-start}

注入翻译器与 locale 源，语言文件保持**扁平**。

```php
use Switon\Core\Attribute\Autowired;
use Switon\Core\LocaleInterface;
use Switon\Core\TranslatorInterface;

class WelcomeService
{
    #[Autowired] protected TranslatorInterface $translator;
    #[Autowired] protected LocaleInterface $locale;

    public function greet(string $name): string
    {
        $this->locale->set('zh-cn');

        return $this->translator->translate('hello', ['name' => $name]);
    }
}
```

```php
<?php
// @resources/i18n/en.php
return [
    'hello' => 'Hello, {name}!',
];
```

```php
<?php
// @resources/i18n/zh-cn.php
return [
    'hello' => '你好，{name}！',
];
```

模板与小段辅助代码可用 `t()`：

```php
$message = t('hello', ['name' => $name]);
```

每个请求设一次 locale：`LocaleInterface::set()` 或 `RequestLocaleFilter`。

已装 `switon/http` 且绑定了 `LocaleInterface` 时，`Http\ServiceProvider` 会**自动注册** `RequestLocaleFilter`；仅当需要**显式排序**时才把它加入 `RequestHandler.filters`。

---

## 核心概念 {#core-concepts}

下文覆盖主要用法决策。

### Locale 状态 {#locale-state}

`LocaleInterface` 表示当前请求的 locale。`Switon\I18n\Locale` 将值规范为小写、`-` 分隔；请求态为空时回退到配置的默认 locale。

### 翻译查找 {#translation-lookup}

单键解析顺序：

1. 当前 locale  
2. 配置的 `fallbacks`  
3. 仅语言部分的回退（如 `zh-cn` → `zh`）  
4. 默认 locale  

语言文件必须扁平：`user.not_found` 是**字符串键**，不是嵌套数组路径。

若整条候选链上**没有任何** locale 文件，抛出 `LocaleFileNotFoundException`。

### 存在性检查 {#presence-checks}

`has($id)` 走相同回退链，只返回 `bool`；**不**发 `TranslationKeyMissing`，**不**抛 `LocaleFileNotFoundException`。

### 消息格式化 {#message-formatting}

默认用 `{key}` 占位符；非字符串占位符会先 **JSON 编码** 再替换。

- ICU：`useICU = true`，需 `intl`，否则 `IntlExtensionRequiredException`；
- 无效 ICU 模式返回原始模板而不是抛异常。

仅在需要复数、select 或 locale 相关数字/日期/货币规则时启用 ICU。

### 缺键 {#missing-keys}

缺键时返回**键本身**，并发出 `Switon\I18n\Event\TranslationKeyMissing`。可监听该事件接监控或内容缺口上报。

### HTTP locale 输入 {#http-locale-input}

`RequestLocaleFilter` 按序读取：**查询参数 → Cookie → Accept-Language**。用 `supported` 限制可接受 locale，用 `aliases` 规范化用户输入（如 `zh` → `zh-cn`）。某来源值不在 `supported` 内时尝试下一来源，最终落到默认 locale。

---

## 配置 {#configuration}

多数应用只需在 `TranslatorInterface` 上配 `dirs` 与 `fallbacks`；覆盖默认 locale 时再配 `LocaleInterface`。

`dirs` **有序**：后列目录可覆盖前列同键。

```yaml
# switon.yml
Switon\Core\TranslatorInterface:
  dirs: ['@resources/i18n']
  fallbacks:
    zh-hk: [zh-tw, zh, en]

Switon\Core\LocaleInterface:
  default: en
```

同时使用 HTTP 输入时：

```yaml
# switon.yml
Switon\Http\Filter\RequestLocaleFilter:
  query: lang
  cookie: locale
  header: accept-language
  supported: [en, zh-cn, zh-tw]
  aliases:
    zh: zh-cn
    zh-hans: zh-cn
    zh-hant: zh-tw
```

---

## 选项发现 {#option-discovery}

用 `config:options` 查看当前安装版本的配置面。

```bash
bash bin/console config:options 'Switon\Core\TranslatorInterface'
bash bin/console config:options 'Switon\Core\LocaleInterface'
bash bin/console config:options 'Switon\Http\Filter\RequestLocaleFilter'
```

---

## 使用注意 {#keep-in-mind}

- Locale 状态按请求隔离；`LocaleInterface::set()` 不会带到后续请求，长驻 worker 复用同一翻译器实例，故语言文件变更后须重载或重启。
- 仅当希望请求输入自动选语言时才加 `switon/http`；否则在你自己的边界调用 `LocaleInterface::set()`。
- 仅当单条消息需要 locale 相关规则（复数形式、select、货币、数字或日期格式化等）时才用 ICU；默认占位符路径足以覆盖多数文案。

---

## 下一步 {#next}

- **在应用中接入 i18n：** [添加国际化](../guides/add-internationalization.md#add-internationalization)
- **从 HTTP 请求绑定 locale：** [HTTP 包](../http/README.md#http-package)
- **理解请求级可变状态：** [Context 包](../context/README.md#context-package)
- **回退与缺键排错：** [FAQ](faq.md#i18n-faq)
- **设计取舍：** [设计说明](design.md#design-rationale)
