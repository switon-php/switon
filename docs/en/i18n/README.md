# I18n Package {#i18n-package}

`switon/i18n` gives Switon apps per-request locale state, flat translation files, fallback lookup, and optional ICU formatting.

**Key features:**

- **Per-request locale** — `Switon\Core\LocaleInterface` keeps each request's locale separate
- **Flat locale files** — locale files are simple PHP maps under `@resources/i18n`
- **Fallback-aware lookup** — one key can fall back through configured locales, then language-only, then default
- **Optional ICU formatting** — use `useICU` only when you need plural or select rules
- **Missing-key signal** — missing keys emit `Switon\I18n\Event\TranslationKeyMissing`
- **HTTP locale input** — with `switon/http`, `Switon\Http\Filter\RequestLocaleFilter` can set locale from request input

---

## Installation {#installation}

**Requirements:** PHP 8.3+, `switon/core`, `switon/event`

Add `switon/http` when you want request-side locale input, and add `ext-intl` when you use ICU formatting.

```bash
composer require switon/i18n
```

The package provider binds `Switon\Core\LocaleInterface` to `Switon\I18n\Locale`
and `Switon\Core\TranslatorInterface` to `Switon\I18n\Translator`.

---

## Quick Start {#quick-start}

Inject a translator and a locale source, then keep locale files flat.

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

`t()` is available for templates and small helper code:

```php
$message = t('hello', ['name' => $name]);
```

Set locale once per request through `Switon\Core\LocaleInterface::set()` or `Switon\Http\Filter\RequestLocaleFilter`.

If `switon/http` is installed and `Switon\Core\LocaleInterface` is bound, `Switon\Http\ServiceProvider` registers `Switon\Http\Filter\RequestLocaleFilter` automatically. Add it to `RequestHandler.filters` only when you need explicit ordering.

---

## Core Concepts {#core-concepts}

The sections below cover the main usage decisions.

### Locale State {#locale-state}

`Switon\Core\LocaleInterface` is the current request's locale source. `Switon\I18n\Locale` normalizes values to lowercase with `-` separators, and falls back to the configured default locale when request state is empty.

### Translation Lookup {#translation-lookup}

One translation key resolves through this candidate chain:

1. current locale
2. configured `fallbacks` entries for the current locale
3. language-only locale derived from a `xx-yy` locale
4. configured default locale

Locale files stay flat. `user.not_found` is one string key, not a nested array path. If no candidate locale file exists at all, translation raises `Switon\I18n\Exception\LocaleFileNotFoundException`.

### Presence Checks {#presence-checks}

`Switon\Core\TranslatorInterface::has($id)` walks the same candidate chain but only returns `bool`. It does not emit `TranslationKeyMissing` and does not raise `LocaleFileNotFoundException`.

### Message Formatting {#message-formatting}

Default translation uses simple `{key}` placeholders.

- non-string placeholder values are JSON-encoded before replacement
- ICU mode is opt-in per call via `translate($id, $bind, useICU: true)`
- ICU mode requires `ext-intl` and raises `Switon\I18n\Exception\IntlExtensionRequiredException` when missing
- invalid ICU patterns return the raw template instead of throwing

Use ICU only when the message needs plural, select, or locale-aware formatting rules.

### Missing Keys {#missing-keys}

A missing key returns the key itself and emits `Switon\I18n\Event\TranslationKeyMissing`. Listen for that event to feed monitoring or content-gap reporting.

### HTTP Locale Input {#http-locale-input}

`Switon\Http\Filter\RequestLocaleFilter` reads locale from query, cookie, or `Accept-Language`, in that order. Use `supported` to restrict accepted locales and `aliases` to normalize user-facing variants.
If a source value is not supported, the filter falls through to the next source and eventually the default locale.

---

## Configuration {#configuration}

Most apps only need `dirs` and `fallbacks` on `Switon\Core\TranslatorInterface`.
Add a `Switon\Core\LocaleInterface` block only when you want to override the default locale.

`dirs` is ordered: later directories can override the same locale key from earlier directories.

```yaml
# switon.yml
Switon\Core\TranslatorInterface:
  dirs: ['@resources/i18n']
  fallbacks:
    zh-hk: [zh-tw, zh, en]

Switon\Core\LocaleInterface:
  default: en
```

When you also use HTTP locale input:

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

## Option Discovery {#option-discovery}

Use `config:options` to inspect the current surface in your installed version.

```bash
bash bin/console config:options 'Switon\Core\TranslatorInterface'
bash bin/console config:options 'Switon\Core\LocaleInterface'
bash bin/console config:options 'Switon\Http\Filter\RequestLocaleFilter'
```

---

## Keep in Mind {#keep-in-mind}

- Locale state is per-request; `LocaleInterface::set()` does not carry into future requests, and long-running workers reuse the same translator instance, so reload or restart after locale files change.
- Add `switon/http` only when request input should choose locale automatically; otherwise call `LocaleInterface::set()` from your own boundary.
- Use ICU only when one message needs locale-aware rules such as plural forms, select choices, or currency, number, or date formatting; the default placeholder path is enough for most messages.

---

## Next {#next}

- **To add i18n to an app:** [Add internationalization](../guides/add-internationalization.md#add-internationalization)
- **To wire locale from HTTP requests:** [HTTP Package](../http/README.md)
- **To understand request-local state:** [Context](../context/README.md)
- **To troubleshoot fallback and missing keys:** [FAQ](faq.md#i18n-faq)
- **To understand the trade-offs:** [Design Rationale](design.md#design-rationale)
