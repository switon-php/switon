---
name: sps-package-release
description: Use for releasing one Switon framework package from the monorepo. Applies when the user asks to release/publish/split a component such as router/core/http, bump a package patch/minor/major version, dry-run a package release, or verify package release readiness before release.
---

# SPS package release

Use this skill for framework package releases under `packages/*`.
Do not use it for app releases or broad monorepo-wide versioning.

## Before a release

```bash
php scripts/check-internal-deps.php
php scripts/package-deps.php
php scripts/package-deps.php --dev
```

Waves from `package-deps.php` are the split order.

## Release model

- One release target at a time.
- Release target format:
  - `package:vX.Y.Z`
  - example: `router:v1.0.1`
- Package releases are independent.
- Ordinary packages use their own package tests as the release gate.
- `skeleton` uses the same release entrypoint, but its gate is distribution-metadata validation rather than the ordinary package test scaffold.
- Publish only after all work is done.
- Tags belong at the end of a successful release flow, never before tests/split.
- Package tags follow the latest tracked commit under `packages/{name}/`, not automatically monorepo `HEAD`.

## Required package files

- `packages/{name}/composer.json`
- For ordinary packages:
  - `packages/{name}/tests/phpunit.xml.dist`

## Release-notes rule

- Before publishing `package:vX.Y.Z`, make sure the release is traceable:
  - package tag target is identified
  - package gate passes
  - user-facing release notes exist or will exist in an accepted release surface
- Accepted release-note surfaces:
  - package-local `CHANGELOG.md`
  - GitHub/GitLab release notes
  - generated notes from commit/PR history
  - another explicit project-approved release record
- Do not require a package-local changelog by default.

## Release-notes workflow

- Do not assume a package changelog should be created or edited.
- Prefer deriving release notes from the actual package change set, tests, and user-visible impact.
- Keep package release notes package-scoped; do not mix unrelated component changes into them.
- If the package keeps a changelog and the user wants it updated, sanity-check that:
  - the version heading matches the release target
  - the content matches this package, not another package
  - obvious user-facing or release-relevant changes are not missing
- If the package does not keep a changelog, prepare a compact release summary for the chosen release surface instead.
- `skeleton` is the default place for framework-level release-note curation. Do not force ordinary packages to mirror that model.
- For `skeleton`, summarize framework-distribution changes rather than only package-local internals.
- For `skeleton`, verify that the release-time resolved component-version record exists or is prepared as part of the release.

## Current publish entrypoint

- Run:
  - `bash build/split.sh publish package:vX.Y.Z`
- Dry-run:
  - `bash build/split.sh publish package:vX.Y.Z --dry-run`

Current script behavior:

1. parse one release target
2. run package gate via `build/release-check.sh <package> --fast`
3. sync split-repo CI into the package subtree
4. publish the package subtree through `git-subsplit`

Operational expectation around the script:

1. AI identifies the latest tracked commit under `packages/{name}/` and the release-note surface first
2. user confirms the release summary if needed
3. then run the publish command

## What to verify before publish

- Target package exists under `packages/{name}`
- Target version starts with `v`
- Ordinary packages pass the package-test-based release gate
- `skeleton` passes the distribution-metadata release gate
- The latest tracked commit under `packages/{name}` is identified for the package tag target
- Release notes are ready on an accepted surface
- If a package changelog is used, its package/version mapping was freshly reviewed for correctness
- `skeleton` has a release-time resolved component-version record

## Tag intent

Final release tagging is two-layer:

- monorepo tag:
  - `{package}-vX.Y.Z`
  - example: `router-v1.0.1`
- split package repo tag:
  - `vX.Y.Z`
  - example: `v1.0.1`

Keep this order:

1. package gate
2. release-note readiness check
3. split/push success
4. final tag creation

If tag automation is not implemented yet, say so clearly and stop after the verified publish steps already supported by the repo.

## Request interpretation

Map requests like these to this skill:

- `release router`
- `publish core:v1.0.2`
- `bump router by one patch and publish it`
- `dry-run http first and see whether it can be released`
- `check whether session can be released`
- `prepare router release notes first, then release`

If the user gives only the package name and no version:

- do not invent the version silently
- inspect tags and recent package context first if useful
- if one obvious target version is not already established, ask for the exact release target

## Pre-publish confirmation

Before final publish, show a compact confirmation block to the user.

Always include:

- current version when it can be determined confidently
- target version
- release summary or changelog draft for this version
- chosen release-note surface
- whether publish will be dry-run or real publish

Use this shape:

```md
Current version: vA.B.C
Target version: vX.Y.Z

Release notes:
- ...

Surface: package changelog | hosted release notes | generated notes | other
Publish mode: dry-run | publish
Confirm before publishing.
```

Version discovery should prefer package tags first:

1. relevant package release tags when available
2. package-local changelog headings when the package actually uses one
3. explicit user instruction

If current version cannot be determined confidently, say that clearly in the confirmation instead of inventing one.

## Response shape

When reporting release readiness or publish results, keep the answer compact and conclusion-led:

- release target
- release summary / release-note surface result
- package gate result
- package tag target result
- publish/dry-run result
- whether final tagging was performed or is still pending
