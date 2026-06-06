# REST API (resources / endpoints / responses)

Use when the user asks to design, review, or modify **REST-style HTTP APIs**: resources, URLs, HTTP methods,
request/response bodies, status codes, or error payloads.

## Quick checklist (for agents)

- **URLs:** Resource nouns, kebab-case, hierarchical only when relationship is tight (e.g. `/orders/{orderId}/items`).
- **Methods:** GET = read, POST = create/side-effect, PUT/PATCH = update, DELETE = remove.
- **Success shape:** Consistent envelope (e.g. `{ code, msg, data }`) across endpoints.
- **Errors:** Use specific status codes (4xx/5xx) plus machine error code + human message (+ optional field `errors`).
- **Pagination:** Standard query params (`page`/`size` or `offset`/`limit`) and response meta (`total`, `page`, `size`,
  `pages`).
- **Filtering/sorting:** Query params for filters and sort; follow existing naming conventions; do not invent new
  patterns.
- **Auth:** Tokens in `Authorization: Bearer <token>`; `401` for missing/invalid token, `403` for insufficient
  permissions.

## 1. Resources and URLs

- **Resources first:** Identify resources (e.g. `User`, `Order`, `OrderItem`) and model URLs around them.
- **Nouns, not verbs:** Use resource nouns in paths (e.g. `/users`, `/orders/{id}`), not action verbs (`/createUser`).
- **Kebab-case:** Use kebab-case for multi-word segments (e.g. `/order-items`, `/admin-roles`).
- **Hierarchy:** Child resources nest under parents when the relationship is tight (e.g. `/orders/{orderId}/items`).

## 2. HTTP methods and semantics

- **GET** — Read data; no side effects. Use for list/detail.
- **POST** — Create or trigger non-idempotent operations.
- **PUT** — Replace an existing resource (idempotent).
- **PATCH** — Partially update an existing resource (idempotent per body).
- **DELETE** — Remove a resource (idempotent).

Prefer to keep one resourceful meaning per (method, path) pair; avoid mixing multiple operations behind the same
endpoint.

## 3. Request and response shape

- **Success envelope:** Use a consistent success shape (e.g. `{ code, msg, data }`), aligned with app request hook (see
  controller/topic in app skills).
- **Error payloads:** Include at least:
    - a machine-parsable **error code** (e.g. `USER_NOT_FOUND`);
    - a human-readable **message** (localized if needed);
    - optional **details** for field errors (e.g. `errors: { field: message }`).
- **Validation errors:** Return structured field-level errors, not just a generic message.

## 4. Status codes

- **2xx:** Success (`200` OK, `201` Created, `204` No Content for delete without body).
- **4xx:** Client errors (`400` bad request / validation, `401` unauthorized, `403` forbidden, `404` not found, `409`
  conflict).
- **5xx:** Server errors (unexpected failures).

Keep status codes consistent across resources; do not overload `200` for every situation when a more specific code
applies.

## 5. Pagination, filtering, sorting

- **Pagination:**
    - Common parameters: `page` (1-based) and `size` (page size), or `offset`/`limit` when needed.
    - Response should include both data and meta (e.g. `total`, `page`, `size`, `pages`).
- **Filtering:**
    - Use query parameters for filters (e.g. `?status=active&role=admin`).
    - For complex search, allow multiple filters or explicit operators (align with app conventions when using a filters
      API).
- **Sorting:**
    - Common pattern: `sort=field` or `sort=-field` (descending); or a separate `order` param.

Follow existing app/API conventions for parameter names and shapes; do not invent new ones when there is already a
standard.

## 6. Backwards compatibility

- **Additive changes:** Prefer adding fields or endpoints over changing existing ones.
- **Avoid breaking:** Do not rename fields, change types, or remove fields without a deprecation period.
- **Versioning:** If incompatible changes are required, use explicit versioning (e.g. `/v2/...` paths or versioned
  hosts) and keep old behavior available for existing clients.

## 7. Verify an API

When the user asks to verify or review an API:

1. List the endpoints and methods involved (resource, URL, method).
2. Check that URLs, methods, and request/response shapes follow the rules above.
3. Verify that status codes and error payloads are consistent and informative.
4. Report briefly: what matches, and where the design deviates from the conventions.

## 8. Authentication and tokens (JWT)

- **Transport:** Carry access tokens in the `Authorization` header (e.g. `Authorization: Bearer <token>`), not in query
  parameters.
- **Issuance/validation:** Use Switon’s JWT component (`Switon\Jwt\JwtInterface::encode()` / `decode()`) for creating
  and validating tokens; the component’s implementation and PHPDoc are the source of truth.
- **API behavior:** For missing/invalid tokens, return `401` (unauthorized); for valid tokens without required
  permissions, return `403` (forbidden) with a structured error payload as described above.

