# I18n reference — questions

**Flow:** Run in a **subagent**: feed it this file + the reference(s). It may use only references and **read** source
files (e.g. open the file for a FQCN found in the reference); **no grep/search** of the codebase. Goal: reference →
FQCN → read that class; avoid blind grep. **Verification:** Prefer a **verification subagent**. Do not load framework or
app skills; use actual code as truth. May read code files; no grep or other code search. Verify every answer; list wrong
answers or reference gaps.

1. What is the interface for translation in Switon?
2. What is the default implementation of that interface?
3. What is the signature of the t() helper and what do the three arguments mean?
4. Where are translation files stored by default?
5. What is the file naming convention for locale files (e.g. for zh-CN)?
6. What shape does a locale file return (PHP)?
7. When useICU is false, how are placeholders replaced and what happens to non-string values in bind?
8. When useICU is true, what extension is required and what happens if it is missing?
9. In ICU mode, what happens to non-scalar values in bind and what happens on format failure?
10. Can you mix simple {key} and ICU syntax in one message key?
11. Which PHP class does ICU mode use under the hood for formatting?
12. Where does the reference say to look up full ICU MessageFormat syntax (PHP vs ICU doc)?
13. How do you write a plural message in ICU (e.g. 0/1/many items)? What is the pattern keyword?
14. In ICU plural pattern, what does # mean?
15. How do you write a select (e.g. gender: male/female/other) message in ICU?
16. How do you format a date in an ICU message (keyword and example)?
17. How do you format a number in an ICU message?
18. I need "You have X items" with correct plural for the locale — simple mode or ICU? What does the template look like?
19. For ICU date formatting, do I pass a DateTimeInterface in bind?
20. Which interface provides get/set/getDefault for locale?
21. How is the default locale configured (e.g. Autowired)?
22. In `switon/http`, which filter sets locale from the request and in what order are query, cookie, and
    Accept-Language checked?
23. For locale zh-CN, what is the fallback candidate order (exact, language-only, default)?
24. When loading messages, in what order are candidate locale files tried?
25. When does `LocaleFileNotFoundException` occur, and how is that different from a missing translation key?
26. When a key is missing in all candidate files, what is used as the template and which event is dispatched?
27. What is the log channel for the missing-key event?
28. In view templates, how do you output a translated string?
29. In PHP classes, should you use t() or TranslatorInterface::translate() and why?
30. From an incoming request with Accept-Language header and no lang query or locale cookie, which class sets the locale
    and in what order are sources considered?
31. When translate() is called for key "user.welcome" with locale zh-CN and bind ["name" => "Alice"], describe the
    sequence: file lookup order, key resolution, placeholder replacement.
32. If the key exists only in en.php and the current locale is zh-CN, how does fallback resolve the key?
33. I want to change the app's default locale (e.g. to zh-CN) — where is that configured?
34. Key exists in both zh.php and zh-CN.php with different text; current locale is zh-CN — which translation wins?
35. I added fr.php under @resources/i18n — do I need to register it somewhere for fallback to use it?
36. Can I change the directory where translation files are loaded from, or is it fixed?
37. When a key is missing and "key used as template" — does the user see the key string (e.g. "user.welcome") or
    something else?
38. I need to log every missing translation key in production — which event do I subscribe to and what is the log
    channel?
39. Does the TranslationKeyMissing event carry the key and the current locale?
40. `RequestLocaleFilter` reads locale from query — what is the typical query param name (e.g. `lang`)?
41. Can I have some keys with simple {key} and other keys with ICU syntax in the same locale file?
42. I'm in a CLI command (no HTTP request) — how do I set the locale so t() uses it?
43. t('user.welcome') with no second argument; the key has {name} in the template — what happens?
44. Simple mode: I pass bind ['name' => null] — what appears in the output?
45. ICU mode: the template has a syntax typo and format fails — what does the user see?
46. Reference says "see view.md" for t() in templates — where does that point and does it mention escaping?
47. Request has `?lang=fr` and no locale cookie; after `RequestLocaleFilter` runs, what does `LocaleInterface::get()`
    return?
48. Key exists in zh.php but not in zh-CN.php; current locale is zh-CN — is the key found via fallback?
49. Does translate() accept a locale override argument or does it always use the current locale from LocaleInterface?
50. Which Composer package provides TranslatorInterface and Translator (for require and docs)?
51. Keys in the file are dot-separated (e.g. user.profile.title) — does the reference require flat keys only or support
    nested array structure?
52. Message content includes HTML (e.g. "Click <a>here</a>") — does t() escape output or should the template/view handle
    escaping?
53. Controller passes $user to view; view needs t('user.welcome', ['name' => $user->name]) — how does the reference say
    to pass bind from controller to template?
54. ICU plural: for English "0 items" / "1 item" / "2 items" — do I need separate branches for zero, one, and other?
55. Languages like Russian have more plural forms (one, few, many) — where do I look up the correct ICU plural
    categories per locale?
56. I need to return translated error messages in an API JSON response — do I call t() or translate() in the controller
    before returning?
57. I added a key to en.php but forgot to add it to zh-cn.php; user locale is zh-CN — what does the user see and is it
    logged?
58. Can translation files live in subdirectories (e.g. @resources/i18n/backend/common.php) or only flat *.php in one
    directory?
59. Key naming: does the reference recommend a convention (e.g. module.entity.message) or is any string allowed?
60. We send emails in the user's locale — in a CLI or job, how do we set the locale before calling t() for the email
    body?
61. Same key appears twice in one locale file (duplicate array key) — which value wins in PHP?
62. I changed en.php on disk during development — do I need to clear cache or restart for the app to pick up changes?
63. Locale file for requested locale (e.g. xy) does not exist — does `LocaleFileNotFoundException` happen on first
    translate() or at bootstrap?
64. Message contains a literal curly brace (e.g. "Use { to open") — in simple mode, how do I avoid it being treated as a
    placeholder?
65. Translator is injected into a validation rule class — can I use TranslatorInterface::translate() there for custom
    error messages?
66. Does TranslatorInterface::translate() take the same arguments as t() (id, bind, useICU) or a different signature?
67. I want to show "No items" / "1 item" / "5 items" in English — how many plural branches do I need in the ICU
    template?
68. `Accept-Language` might set locale for the whole app — can we run `RequestLocaleFilter` only for certain routes (
    e.g. exclude API)?
69. t() with useICU true but the server has no intl extension — when does the `IntlExtensionRequiredException` occur (
    first t() call or
    earlier)?
70. We need to render the same key in two locales in one response (e.g. show both en and zh) — do we call translate
    twice with different locale, and does the API support a locale parameter?
71. DateTimeInterface in bind for ICU date format — which timezone is used (user locale, app default, or the object's)?
72. I need to check whether a key exists before rendering a fallback — is there a has(key) method or do I translate and
    compare?
73. Filter A sets locale to `fr`; later filter B sets it to `en` — when the controller calls `t()`, which locale is
    used?
74. Plural: I pass bind ['count' => 0] with ICU — does English use the "one" or "other" branch for 0?
75. Long translation value: can I break the string across lines in the PHP array (e.g. for readability)?
76. Where is LocaleInterface typically bound (config file, service provider, or both)?
77. Default locale was never set — what happens when we call get() before any set()?
78. API endpoint returns a list of entities; each has a "status" field we want translated — where do we call t() (
    controller, serializer, or view)?
79. We have 20 locale files; we add a new locale — do we copy an existing file and translate, or is there a scaffold
    command?
80. Listener sets locale from query/cookie/Accept-Language — does it also write the resolved locale back to the cookie
    for the next request?
