# I18n FAQ {#i18n-faq}

Short answers for setup, configuration, and common translation issues.

> **Prerequisites:** [I18n Package](README.md)

## Configuration {#configuration}

### Where do I configure i18n? {#configure}

Use `switon.yml` for `Switon\Core\TranslatorInterface`. Add
`Switon\Core\LocaleInterface` only if you want to override the default locale.
If you also use HTTP locale input, configure `Switon\Http\Filter\RequestLocaleFilter` too.

### How do I change the translation directory? {#change-dir}

Set `dirs` under `Switon\Core\TranslatorInterface`. It is an ordered list of translation directories.

Later directories can override the same locale key from earlier directories.

### How do I customize locale fallback for one region? {#custom-fallbacks}

Set `fallbacks` under `Switon\Core\TranslatorInterface`, for example `zh-hk: [zh-tw, zh, en]`.

### How do I change the default locale? {#default-locale}

Set `default` under `Switon\Core\LocaleInterface`.

### How do I inspect the exact option surface? {#option-discovery}

Run `config:options` with the interface or class you configure.

```bash
bash bin/console config:options 'Switon\Core\TranslatorInterface'
bash bin/console config:options 'Switon\Core\LocaleInterface'
bash bin/console config:options 'Switon\Http\Filter\RequestLocaleFilter'
```

### Can I use `t()` instead of injecting a translator? {#helper}

Yes, but treat it as a lightweight helper. In services and boundaries, prefer injecting `Switon\Core\TranslatorInterface`.

### What does `has()` check? {#has}

`has($id)` uses the same fallback chain as `translate($id)`, but it only returns `true` or `false`.
It does not emit `TranslationKeyMissing` and it does not throw `LocaleFileNotFoundException`.

## Behavior {#behavior}

### What happens when a key is missing? {#missing-key}

The translator returns the key itself and emits `Switon\I18n\Event\TranslationKeyMissing`.

If no candidate locale file exists at all, translation throws `Switon\I18n\Exception\LocaleFileNotFoundException` instead.

### Does locale leak between requests? {#locale-leak}

No. Locale lives in per-request context through `Switon\Core\LocaleInterface`.

### Why is my array or object placeholder rendered as JSON? {#json-placeholder}

Default placeholder replacement normalizes non-string values to JSON strings. If you need plural or select rules,
switch to ICU mode instead.

### What if ICU is unavailable? {#icu-unavailable}

`useICU: true` requires `ext-intl`. Without it, the translator throws `Switon\I18n\Exception\IntlExtensionRequiredException`.

### How does HTTP locale input choose a value? {#http-order}

Query parameter, cookie, header, then default locale. Use `supported` and `aliases` only when you need to constrain
request input.

If a value is not in `supported`, the filter tries the next source and then falls back to the default locale.

## Troubleshooting {#troubleshooting}

### Translation files changed, but output did not {#not-updating}

Restart or reload long-running workers after locale files change.

### Dot keys like `user.not_found` are not working {#dot-keys}

Locale files must be flat PHP maps. Dot keys are ordinary string keys, not nested array paths.

### ICU translation fails at runtime {#icu-runtime-error}

Check whether `ext-intl` is installed first. If it is, the ICU pattern itself is usually the next thing to inspect.
