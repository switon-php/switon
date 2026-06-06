# Contributing to Switon

Focused changes; follow neighboring package patterns. Commits: `GIT_COMMIT_STANDARDS.md`.

## Before submit

- atomic scope; interface PHPDoc updated when the public contract changes
- `bash tests/run.sh <package>` for touched components
- docs/examples match code; identifiers verified against code or discovery commands — do not invent names
- package README stays lightweight; detailed docs live in `docs/en/{name}/`

## Review focus

Neighboring consistency, boundary safety, PHPDoc/docs vs runtime, meaningful independent tests.
