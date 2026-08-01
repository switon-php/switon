# Add internationalization {#add-internationalization}

Use this guide when one working page or API path now needs locale-aware text, request-scoped locale selection, or persisted language preference without turning translation platforms, localized routing, or frontend-specific i18n stacks into the first answer.

## Choose the first internationalization boundary {#internationalization-boundary}

Start with the smallest boundary that matches the real locale decision.

| Boundary | Best when | Keep in mind |
|---|---|---|
| `TranslatorInterface::translate()` | one service, page, or API response now needs locale-aware strings | best first move when one working path already exists and only the output text should change |
| `LocaleInterface::set()` | one explicit request path should switch locale right now | affects the current request context only; it does **not** persist the next request by itself |
| `RequestLocaleFilter` | locale should follow request input such as query, cookie, or `Accept-Language` | detection order is fixed: query → cookie → header → default; with `switon/http`, the filter is auto-registered, meaning the HTTP package adds the built-in lifecycle listener for you when `LocaleInterface` exists |
| `CookiesInterface::set()` | the user's locale choice should survive into the next request | `RequestLocaleFilter` reads the cookie later, but it never writes one for you |
| `translate(..., true)` with ICU | plural or select logic is the real translation problem | requires the `intl` extension; do not make ICU the first step when plain `{key}` placeholders are enough |

One common application shape is:

- one default locale file under `@resources/i18n`
- one translated service or page response through `TranslatorInterface`
- HTTP locale detection for request-driven choice
- one cookie write only after you decide the locale should persist across requests

## Recommended build order {#build-order}

### 1. Start after one page or API path is already correct {#build-stable-path}

Internationalization is much easier after you trust the existing route, controller, service, and response shape.
Do not use i18n as the first explanation for routing, rendering, or JSON contract problems.

### 2. Create one default locale file first {#build-default-locale}

Start with one real default locale such as `en.php` under `@resources/i18n`.
That gives the translator one authoritative baseline before regional or language-only fallback matters.

If none of the candidate locale files exists, translation raises `LocaleFileNotFoundException`.
So the first i18n success line should always include one real default file.

### 3. Translate one service-owned message before you widen scope {#build-message}

Pick one message you can explain in one sentence:

- a dashboard title
- a welcome line
- one API status message
- one email-ready string produced by an application service

That proves the translation boundary without turning the first change into a full message-catalog project.

### 4. Let one locale source win clearly {#build-source}

Choose the real locale source for the first path:

- configured default locale when the app is single-locale by default
- `LocaleInterface::set()` when one request path changes language explicitly
- `RequestLocaleFilter` when HTTP input should decide locale across normal requests

Do **not** combine several competing locale setters before the first flow is clear.
If the app applies a global router prefix such as `/api`, keep any locale-switch endpoint mapping prefix-free and confirm the real public URL with `bash bin/console router:prefix`.

### 5. Persist locale only after request-local switching works {#build-persist-later}

First prove that the current request renders the right locale.
Only then decide whether the next request should remember that choice.

When persistence is needed, do both steps explicitly:

- call `LocaleInterface::set($locale)` for the current request
- call `CookiesInterface::set('locale', $locale, ...)` for the next request

### 6. Add regional overrides before you add workflow complexity {#build-regional-overrides}

When `zh-cn` should differ slightly from `zh`, let regional files override only the keys they need.
This is where Switon's fixed fallback is strongest:

- `zh-cn.php`
- then `zh.php`
- then the configured default locale such as `en.php`

Use that ordered fallback before you add a database, translation platform, or content tooling.

### 7. Add ICU and missing-key observability only when they are real problems {#build-advanced}

Plain placeholders cover many first integrations.
Move to ICU only when plural or select rules are genuinely needed.
Observe `Switon\I18n\Event\TranslationKeyMissing` only when content gaps become an operational concern.

### 8. Stop before route-localization or frontend-localization design {#build-stop}

The first i18n win is usually small:

- one stable locale source
- one working translation directory
- one persisted cookie only if the app truly needs it

You do **not** need localized route trees, frontend framework wrappers, or translation management platforms before one page or API path feels correct.

## Minimal wiring {#minimal-wiring}

Use short code only to show the seams between locale files, translation calls, HTTP locale detection, and optional cookie persistence.
Application names below are illustrative. The stable part is the Switon i18n and HTTP surface.

### Start with flat locale files {#wiring-locale-files}

Keep the first translations in `@resources/i18n` as flat PHP maps:

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

This is enough for the first successful path.
Add a regional file such as `zh-cn.php` later only when that locale really needs different wording for a small subset of keys.

### Translate through one service boundary {#wiring-translator}

Start with one service or page data loader that owns the message assembly:

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

This keeps translation decisions near application-owned output assembly instead of scattering them across unrelated utilities.

### Let HTTP requests choose locale only when that matches the boundary {#wiring-http-locale}

If request input should drive locale, a small config surface is enough:

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

With `switon/http`, `RequestLocaleFilter` is registered automatically when `LocaleInterface` exists.
If the defaults already fit, you often do not need to configure the filter keys at all.

### Persist locale preference only when the next request should remember it {#wiring-persist-locale}

Use an application endpoint or action to switch locale explicitly:

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

This `GET` route is only a minimal example of the locale switch boundary. In a real app, prefer the verb and CSRF posture that match how users change preferences.

Two boundaries matter here:

- `LocaleInterface::set()` changes the **current** request immediately
- `CookiesInterface::set()` helps the **next** request choose the same locale later

If the app adds a global prefix such as `/api`, the public path becomes `/api/settings/locale` even though the controller mapping stays `'/settings/locale'`.

If you change the cookie key in `RequestLocaleFilter`, keep the controller write key aligned with that config.

### Use ICU only for real plural or select rules {#wiring-icu}

Plain placeholders are the best first default.
When plural or select logic is the actual problem, switch one key to ICU mode:

```php
$message = $translator->translate('items.count', ['count' => 3], true);
```

Keep that step narrow.
ICU should solve a real message-formatting problem, not become the default for every first translation.

## What to avoid first {#pitfalls}

Keep the first internationalization path intentionally narrow.

Avoid these too early:

- **Returning nested arrays from locale files** — Switon expects flat `array<string, string>` maps, not tree-shaped translation files
- **Assuming the filter persists locale automatically** — `RequestLocaleFilter` reads locale; it does not write the cookie for you
- **Forgetting `supported` or `aliases` when request-side locale input needs normalization** — negotiation can constrain input to your supported locales and map variants such as `zh-hans -> zh-cn`, but only when you configure those keys
- **Skipping the default locale file** — without any candidate locale file, translation raises `LocaleFileNotFoundException`
- **Jumping straight to ICU for simple strings** — plain `{key}` placeholders are the better first path
- **Expecting translation file edits to hot reload in workers** — long-running runtimes need restart or reload after locale-file changes
- **Inventing localized routes or template helpers first** — prove one translated page or API path before broader presentation conventions

> I know where locale comes from, where translated messages live, when locale changes stay request-local, and when cookie persistence is worth adding.

## Go deeper {#go-deeper}

- **To add locale-aware pages after the first HTML path already works:** [Build HTML pages](build-html-pages.md#build-html-pages) · [View](../../packages/view/docs/README.md)
- **To keep locale-aware API output small and explicit:** [Build an API](build-an-api.md#build-an-api) · [HTTP](../../packages/http/docs/README.md)
- **To go deeper on translation files, fallback, and ICU:** [I18n](../../packages/i18n/docs/README.md)
- **To keep locale selection request-local and runtime-safe:** [Context](../../packages/context/docs/README.md) · [I18n design rationale](../../packages/i18n/docs/design.md#design-rationale)
- **To persist, delete, or scope locale cookies correctly:** [Cookies](../../packages/http/docs/cookies.md#cookies)
- **To make validation errors use the same locale boundary:** [Validation](../../packages/validation/docs/README.md)
- **To observe content gaps instead of failing requests:** [Event system](../../packages/event/docs/README.md) · [I18n FAQ](../../packages/i18n/docs/faq.md#i18n-faq)
- **To test locale-driven output before widening the message catalog:** [Add testing to a working app](add-testing-to-a-working-app.md#add-testing-to-a-working-app)
