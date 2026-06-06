---
name: sps-framework-optimize
description: Objective-driven optimization for AI discoverability, cross-package navigation, and @see mesh.
---

# SPS framework optimize (Objective-Driven)

**Core Objective**: Transform the Switon monorepo's `@see` references from exhaustive dictionaries into high-signal, layered navigation meshes.

*Premise: AI and humans need curated "next logical hops" (Road-signs), not an overwhelming dump of every related class.*

## Your Mission
You are an Information Architect for the framework. When auditing or optimizing cross-package navigation or `@see` annotations, your goal is to minimize fan-out and create obvious, logical jumping points to the most critical usage contexts.

## Evaluation Criteria (What good looks like)
Use your judgment to align navigation edges with these architectural principles:

1. **Contract-First Flow**: When moving between layers, the next hop should almost always be an Interface (`XxxInterface`), not a concrete implementation.
2. **Layered Jumps (No Teleportation)**: If logic flows A → B → C, do not link A directly to C. Link A to B, and let B link to C.
3. **Curated Interface Navigation**: Interface class-level PHPDocs should act as high-value road signs, not exhaustive method indexes. Restrict method-level `@see` links (`Interface::method()`) only to the truly essential workflows or mechanism entry points (e.g., 3-5 links for a complex core contract). Avoid listing every single getter/setter.
4. **Reverse Edges (Upstream Context)**: For low-level dependency packages (e.g., `db`, `query`), add `Typical consumer` edges pointing to stable upstream consumers. This answers the "Who actually uses this?" question instantly.

## How to execute
Do not blindly follow a script sequence. When assigned an optimization task:
- Evaluate the current navigation "noise level" of the target interfaces.
- If there are too many edges (the docblock looks like a dictionary), prune them down to the most critical functional paths.
- If reverse context is missing, use search/grep tools (or the provided helper scripts in `skills/sps-framework-optimize/scripts/`) to find low-fan-in consumers and wire them up.

## Legacy Script Reference (Use as needed)
If you need automated assistance to map dependencies or validate `@see` noise, you may still rely on these provided framework scripts:
- **Dependency Mapper**: `php skills/sps-framework-optimize/scripts/use-deps-tree.php` (Use `--reverse` to find upstream consumers, e.g. `--max-in=3`).
- **@see Noise Sweeper**: `php skills/sps-framework-optimize/scripts/phpdoc-see-filter.php` (Use to find interfaces listing too many methods).

## Constraints
- **Curated, not exhaustive**: Stop trying to list every related class. `@see` is a navigation aid, not a reverse index.
- Do not use this skill for Beacon app-facing tasks or end-user application development.