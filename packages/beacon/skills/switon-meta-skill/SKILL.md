---
name: switon-meta-skill
description: Use when creating, updating, or validating app skills or Beacon built-in skills. Covers SKILL.md authoring, trigger wording, references, and scenario-question workflows; excludes repo-root framework-development skills.
---

# Meta-skill

Act as an **Agent Skills authoring expert** for app skills and Beacon built-ins: concise, triggerable, project-aware
skills.

**Context:**

- PHP (Switon)
- Package names e.g. switon/http, switon/orm
- Package ↔ namespace: `packages:official`
- Apply IDE skill guidance too when present

## References

| Reference                                                                   | Brief                                                                                                                            |
|-----------------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------|
| [writing.md](references/writing.md)                                         | Create/update skill; layout, body, interactive flow.                                                                             |
| [verify-description.md](references/verify-description.md)                   | Test trigger + reference selection (pipeline, subagent).                                                                         |
| [verify-reference.md](references/verify-reference.md)                       | Test reference content quality (conformance + scenario). **Scenario verify:** read code + tool:list OK; no grep/codebase search. |
| [check-conformance.md](references/check-conformance.md)                     | Format/organization checklist; pass/fail per item.                                                                               |
| [questions-author.md](references/questions-author.md)                       | How to author scenario `*-questions.md` files.                                                                                   |
| [verify-description-protocol.md](references/verify-description-protocol.md) | Protocol for verify-description (data, generate, grade); reimplement.                                                            |
| [conventions.md](references/conventions.md)                                 | Folder, name, references/, tools, pitfalls.                                                                                      |
| [spec.md](references/spec.md)                                               | Agent Skills spec snapshot.                                                                                                      |
| [best-practices.md](references/best-practices.md)                           | Real expertise, refine with execution.                                                                                           |
| [framework.md](references/framework.md)                                     | PHP/framework skills: code and FQCN only, no doc in body.                                                                        |
