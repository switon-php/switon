# DB Internals {#db-internals}

Traces cross-driver identifier quoting and transaction error-handling design when README and `design.md` are not enough,
not first-path adoption.

## When to read this folder {#scope}

Read these notes when you are:

- tracing cross-driver SQL behavior
- debugging transaction state or rollback expectations
- reviewing package internals instead of learning first-path usage

---

## Available notes {#available-notes}

- [Identifier quoting design](identifier-quoting-design.md#identifier-quoting-design) — why manual SQL uses
  `[identifier]` and how adapters
  translate it per driver
- [Transaction error handling design](transaction-error-handling-design.md#transaction-error-handling-design) — why
  rollback behavior differs between
  `ClientInterface` and `TransactionManagerInterface`

---

## Next {#next}

Go back to the package-facing guides when you need the normal user path:

- [DB Package](../README.md)
- [Design Rationale](../design.md#design-rationale)
- [FAQ](../faq.md#db-faq)
