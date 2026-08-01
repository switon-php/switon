# Design Rationale {#design-rationale}

`switon/storage` keeps one small local blob boundary and leaves file listing and remote storage policy to the application.

## See also {#see-also}

- [Storage Package](README.md) — usage, configuration, and boundaries
- [FAQ](faq.md#storage-faq) — short answers for common storage issues

---

## Core Problem {#core-problem}

File handling drifts when path rules, naming rules, file listing, and remote-storage assumptions get mixed together.

## Primary Goal {#primary-goal}

`switon/storage` standardizes blob access, not file management policy.

- local bytes stay easy to store and read
- application metadata stays outside the blob store
- file ownership and listing stay explicit

## Trade-Off {#trade-off}

- **Benefit:** storage code stays small.
- **Benefit:** keys stay portable and root-bounded.
- **Cost:** file listing, search, and remote storage stay outside the package.

## Deliberate Limits {#deliberate-limits}

- key-addressed storage, not file browsing
- local filesystem first
- string and stream access both supported
- `putFile()` is a convenience helper, not a file system policy layer
