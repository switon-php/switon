---
sidebar_label: I18n FAQ
description: I18n：dirs/fallbacks、has()、缺键、ICU 与 RequestLocaleFilter。
---

# I18n FAQ {#i18n-faq}

面向搭建、配置与常见翻译问题的短答。

> **前置：** [I18n 包](README.md#i18n-package)

## 配置 {#configuration}

### i18n 在哪配？ {#configure}

在 `switon.yml` 配 `Switon\Core\TranslatorInterface`；仅覆盖默认 locale 时再加 `LocaleInterface`。用 HTTP 选语言时还要配 `RequestLocaleFilter`。

### 怎么改翻译目录？ {#change-dir}

在 `TranslatorInterface` 下设 `dirs`（**有序**列表）。后列目录可覆盖前列同键。

### 怎么为某地区定制回退？ {#custom-fallbacks}

在 `TranslatorInterface` 下设 `fallbacks`，例如 `zh-hk: [zh-tw, zh, en]`。

### 怎么改默认 locale？ {#default-locale}

在 `LocaleInterface` 下设 `default`。

### 怎么看当前可配置项？ {#option-discovery}

对要配置的接口或类执行 `config:options`。

```bash
bash bin/console config:options 'Switon\Core\TranslatorInterface'
bash bin/console config:options 'Switon\Core\LocaleInterface'
bash bin/console config:options 'Switon\Http\Filter\RequestLocaleFilter'
```

### 能用 `t()` 代替注入翻译器吗？ {#helper}

可以，但视为轻量辅助；服务与边界层优先注入 `TranslatorInterface`。

### `has()` 检查什么？ {#has}

与 `translate()` 相同回退链，只返回 `true`/`false`；不发 `TranslationKeyMissing`，不抛 `LocaleFileNotFoundException`。

## 行为 {#behavior}

### 缺键会怎样？ {#missing-key}

返回键本身并发出 `TranslationKeyMissing`。若整条链上**没有任何** locale 文件，则抛 `LocaleFileNotFoundException`。

### locale 会在请求间泄漏吗？ {#locale-leak}

不会。locale 经 `LocaleInterface` 存在**请求级**上下文中。

### 为什么数组/对象占位符变成 JSON？ {#json-placeholder}

默认占位符替换会把非字符串规范为 JSON 字符串。需要复数/select 请改用 ICU。

### 没有 ICU 扩展怎么办？ {#icu-unavailable}

`useICU: true` 需要 `ext-intl`，否则 `IntlExtensionRequiredException`。

### HTTP 如何选 locale？ {#http-order}

查询参数 → Cookie → 头 → 默认 locale。用 `supported`/`aliases` 约束输入；值不在 `supported` 内则尝试下一来源，最后默认 locale。

## 排错 {#troubleshooting}

### 改了语言文件但输出没变 {#not-updating}

长驻 worker 需重启或重载。

### `user.not_found` 这类点号键不生效 {#dot-keys}

文件必须是扁平 PHP map；点号是**普通字符串键**，不是嵌套路径。

### ICU 运行时失败 {#icu-runtime-error}

先确认已装 `ext-intl`；已装则重点检查 ICU 模式本身。
