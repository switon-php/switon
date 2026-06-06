# Check conformance

Format/organization vs spec and conventions.

- **Scope:** Full skill or user-specified file(s).
- **Ref depth:** SKILL.md → refs one level only; ref → ref is allowed, but avoid deep chains (a→b→c).
- **Terminology:** Consistent; no time-sensitive or "before date X".
- **Conciseness:** No verbosity or obvious restatements. When checking: is it too verbose for an AI reader?
- **Long paragraph → list/checklist:** One idea per item.
- **FQCN:** First mention per file = FQCN; later same file may be short. Report file + name where first is short.

**Report:** Pass/fail per item; one concrete fix per failure (e.g. "references/i18n.md: LocaleFileNotFoundException →
FQCN").
