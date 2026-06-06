# Versioning Standards

For package releases, framework distribution releases, tags, and release-time version records.

## Scope

- Applies to publishable packages under `packages/*`.
- Applies to release tags, release notes, and release-time component version snapshots.
- `switon/skeleton` is a package and follows the same release mechanism as other publishable packages.

## Core model

- Every publishable package owns its own version.
- Use the same release mechanism for all publishable packages.
- Do not add a separate `framework` package just to represent framework versioning.
- `switon/skeleton` is the special package whose version represents the official Switon framework distribution.

## Why this model

- Documentation maintenance can continue after a package's releaseable code is already ready, so documentation progress must not automatically move package release tags.
- A package tag should describe that package's real releaseable code state, not whichever later monorepo commit happened to touch docs, changelogs, or unrelated components.
- `switon/skeleton` is the framework release surface, so framework-level change summaries belong there by default instead of forcing every package to carry the same changelog burden.
- `packages/{name}/` is the practical version boundary for that package, so the package tag target should come from that directory's latest tracked commit.

## Package versioning

- Version each publishable package independently.
- Package versions describe that package's own evolution, not the whole monorepo state.
- Do not force all packages onto one shared version line.
- New packages may start from their own initial version; they do not inherit an ecosystem-wide major version.
- Do not treat the monorepo `HEAD` as the package version boundary when the package itself did not change.

## Framework distribution versioning

- Treat `switon/skeleton` as the framework distribution package.
- The released version of `switon/skeleton` is the official framework release version.
- Framework release notes should summarize what changed in the component set used or recommended by that `switon/skeleton` release.
- `switon/skeleton` does not need to include every publishable package in the repository.
- Packages not included in `switon/skeleton` remain independently versioned packages, not part of the default framework distribution by default.
- Do not force ordinary package test scaffolding rules onto `switon/skeleton` when that would pollute the application template.

## Tags

- Release tags for packages must use `{package}-v{version}`.
- `{package}` is the `packages/{name}` directory name, for example `router`, `http`, or `skeleton`.
- Examples:
  - `router-v1.4.2`
  - `http-v2.1.0`
  - `skeleton-v1.1.0`
- Do not use bare monorepo-wide tags such as `v1.2.0` for package releases.
- Do not introduce a separate `switon-v{version}` tag line while `switon/skeleton` is the framework distribution package.
- Package tags should point to the package's intended release state, not automatically to current monorepo `HEAD`.
- Use the latest commit under `packages/{name}/` as the default package tag target.
- If a package has not changed recently, it is valid to tag that older `packages/{name}/` commit instead of current monorepo `HEAD`.

## Release-time version records

- A framework distribution release must record the resolved component versions used at release time.
- Version ranges in `composer.json` are not enough to define a reproducible framework release.
- The release-time record is the immutable answer to “which package versions were in this framework release”.
- This record may be stored as a lock-backed snapshot, release manifest, or equivalent release artifact.
- The record must at least cover the packages included or recommended by that `switon/skeleton` release.

## Release notes

- Package release notes focus on the package being released.
- Package release notes must be traceable, but they do not require a package-local `CHANGELOG.md`.
- Prefer clean commit history (`GIT_COMMIT_STANDARDS.md`), package tags, and generated or platform-hosted release notes over mandatory package changelog files.
- Do not create package changelog-only commits just to make tagging possible.
- `switon/skeleton` release notes focus on the framework distribution view:
  - added or removed recommended packages,
  - changed package version ranges or resolved package versions,
  - notable upgrades that framework users should care about,
  - migration notes when the recommended composition changes.
- `switon/skeleton` is the default place for framework-level release-note curation.
- Record, for each `switon/skeleton` release, the component version changes that matter to framework users.
- Do not build framework release notes by dumping every component changelog verbatim.
- Summarize component changes from the framework user's point of view.

## Release checks

- Every publishable package should pass the same package release entrypoint.
- The repository entrypoint for package release checks is `bash build/release-check.sh <package>`.
- `make release-check PKG=<package>` is the convenience wrapper.
- An ordinary package without the minimum release metadata or package test entry is not release-ready.
- `switon/skeleton` is the exception: validate its distribution metadata without forcing the ordinary package test scaffold.

## Minimum release-ready shape

- A publishable package must have `composer.json`.
- An ordinary publishable package must have `tests/phpunit.xml.dist`.
- An ordinary package that does not meet the minimum release-ready shape must not be treated as release-ready.
- `switon/skeleton` is release-ready through its distribution-metadata path, not through the ordinary package test scaffold.

## Changelog policy

- Package-local `CHANGELOG.md` files are optional, not mandatory.
- If a package keeps one, treat it as release documentation, not as the source of truth for package version boundaries.
- `switon/skeleton` should keep framework-facing release notes or an equivalent release record.
- The framework-facing record should capture the resolved component versions and the user-relevant changes in that framework release.

## Practical boundary

- Keep the release model simple:
  - packages release independently,
  - `switon/skeleton` represents the official framework distribution,
  - release tags stay package-scoped,
  - package tags follow the latest tracked commit under `packages/{name}/` rather than monorepo `HEAD`,
  - package changelogs are optional,
  - framework distribution releases record resolved package versions.
- Prefer a small number of stable rules over a theoretical perfect SemVer taxonomy that the team will not follow consistently.
