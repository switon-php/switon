---
name: switon-api
description: API design and behavior—RESTful endpoints, status codes, response shape, pagination, filtering, and error handling. Use when the user mentions API, interface design, REST, RESTful, endpoint, status codes, pagination, filtering, sorting, or error payloads.
---

# API design (REST / responses / pagination)

Use this skill when the user asks to design, review, or modify **HTTP APIs** in Switon-based apps: RESTful endpoints,
request/response shape, status codes, error payloads, pagination, filtering, or sorting. Suitable for API design, REST
style, status codes, response envelopes, and pagination/filtering rules.

## By topic — read the reference for the task

| Topic                      | When                                                                                                                             | Reference                                        |
|----------------------------|----------------------------------------------------------------------------------------------------------------------------------|--------------------------------------------------|
| **REST API**               | Design or review REST-style endpoints (resources, URLs, methods, request/response bodies). REST interfaces and resource modeling | [references/rest-api.md](references/rest-api.md) |
| **Responses & errors**     | Define or verify response envelope, status codes, and error payload structure. Response shape, error codes, and error messages   | [references/rest-api.md](references/rest-api.md) |
| **Pagination & filtering** | Design or review pagination, filtering, and sorting parameters and payloads. Pagination, filtering, and sorting                  | [references/rest-api.md](references/rest-api.md) |

## When NOT to use this skill

- **App internals only**: For controller/router/entity/view/command implementation details inside a specific app, use
  `switon-php-coding`.
- **Documentation only**: For writing or updating API docs in the Switon monorepo `docs/` or READMEs, use the repo-root
  documentation skill (`sps-docs`).
- **Non-HTTP APIs**: For message-queue, RPC, or CLI-only workflows, prefer the relevant component or CLI skills.

## How to use this skill

1. When this skill is active, act as an **API design expert**: favor consistent RESTful design, clear error contracts,
   and predictable pagination/filtering.
2. Identify that the user is talking about **HTTP APIs** (REST, endpoints, status codes, pagination, filtering, sorting,
   error payloads).
3. Use the table above to select and read the corresponding `references/*.md` file in full (start with `rest-api.md`).
4. Apply the design and behavior rules from the reference, then map them onto the concrete app (routes, controllers,
   entities, config).
5. When you need to align with the actual runtime behavior, read the relevant Switon package source and PHPDoc under
   `vendor/switon/` (e.g. HTTP, controller, request hook packages).
6. When both API design and app internals are involved, **use this skill for the API surface** and `switon-php-coding`
   for the underlying implementation.
