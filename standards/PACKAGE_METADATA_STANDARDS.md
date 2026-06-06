# Package Metadata Standards

For `composer.json` metadata quality, discoverability, and consistency in publishable packages and app templates.

## Scope

- Applies to publishable packages under `packages/*`.
- Applies to app templates and distributions such as `app-admin` and `switon/skeleton` where `composer.json` is user-facing.
- Focuses on metadata quality, not dependency policy or release tagging rules.

## Goals

- Make each package easy to identify from package indexes and search results.
- Keep package metadata consistent across the repository.
- Treat `composer.json` as a user-facing entry surface, not only a dependency manifest.

## Required metadata

- Every publishable package must define:
  - `name`
  - `description`
  - `keywords`
  - `homepage`
  - `license`
  - `authors`
  - `support.source`
  - `support.issues`
- If the package has a published doc entry under `docs/en/{name}/README.md`, it should also define `support.docs`.

## Description rules

- `description` must explain what the package is and what responsibility it owns.
- Keep `description` to one sentence.
- Prefer stable capability and boundary wording over implementation trivia.
- Write package descriptions from the adopter's point of view, not the monorepo maintainer's point of view.
- Avoid empty umbrella descriptions such as `The Switon Framework`.
- Do not mention other frameworks in `description` unless the package genuinely integrates with them.
- Do not use competitor names as search bait.

Preferred shape:

- `<capability> for Switon Framework`
- `<responsibility A>, <responsibility B>, and <responsibility C> for Switon Framework`

## Keyword rules

- `keywords` should usually contain 4 to 5 high-signal entries.
- Keep `switon` as the first keyword for Switon-owned packages.
- Use domain and capability terms that help discovery, such as `routing`, `orm`, `smtp`, or `scheduler`.
- Avoid vague filler terms such as `framework`, `php`, or `library` unless they materially improve discovery for that package.
- Do not add competitor framework names as keywords.
- Do not add keywords that the package does not actually own.
- Avoid duplicating near-synonyms unless both are strong search terms.

## Support links

- `support.source` should point to the canonical source repository URL.
- `support.issues` should point to the canonical issue tracker URL.
- `support.docs` should point to the published docs URL when the package has public docs.
- Keep `homepage` and `support.source` aligned by default.
- If `homepage` intentionally points somewhere else, such as a docs landing page, the distinction must be deliberate and still leave source and issues easy to reach through `support`.

## Type rules

- Ordinary publishable packages should explicitly use `type: library`.
- Composer plugins should explicitly use `type: composer-plugin`.
- App templates should explicitly use `type: project`.
- Do not rely on Composer defaults when the explicit type improves consistency or discoverability.

## Field order

- Keep top-level `composer.json` fields in this order when present:
  1. `name`
  2. `description`
  3. `type`
  4. `keywords`
  5. `homepage`
  6. `license`
  7. `authors`
  8. `support`
  9. `version`
  10. `require`
  11. `require-dev`
  12. `suggest`
  13. `autoload`
  14. `autoload-dev`
  15. `extra`
  16. `minimum-stability`
  17. `prefer-stable`
  18. `repositories`
  19. `config`
- Keep ordering stable to reduce noisy diffs and make metadata review faster.

## App template boundary

- App templates such as `app-admin` and `switon/skeleton` are also discoverability surfaces.
- Their `description` and `keywords` must describe the template or distribution itself, not the whole framework in the abstract.
- Do not let app templates present themselves as the canonical definition of all of Switon.

## Review checklist

- Does the description say what the package is and what it owns?
- Can a user identify the package from search results without opening the repository?
- Are the keywords high-signal and free of competitor-name bait?
- Are docs, source, and issue links directly reachable?
- Does the package type reflect how the package is meant to be consumed?
- Is the top-level field order consistent with the repository standard?
