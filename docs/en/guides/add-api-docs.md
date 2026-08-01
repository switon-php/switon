# Add API docs {#add-api-docs}

Use this guide when one working HTTP path already has stable routes, request/response shapes, authentication boundaries, and any client-visible `429` behavior you plan to document.
Client callers now need a durable API contract without hand-maintaining one giant spec file.

## Choose the first contract surface {#contract-surface}

Start with the client-visible boundary that is already stable.

| Surface | Best when | Keep in mind |
|---|---|---|
| login endpoint | clients need the first credential-to-auth contract | document whether success returns a token, sets a cookie, or does both in app-owned behavior |
| current-user endpoint | UI or API clients need one authoritative identity snapshot | keep the response shape compact and stable before you optimize it |
| protected business endpoint | real domain routes now require authenticated or role-aware access | make denial outcomes explicit so clients can distinguish `401` from `403` |
| shared auth error responses | more than one protected endpoint returns the same deny pattern | define shared responses once in `openapi/components/*.yml` instead of duplicating text everywhere |

A practical first contract often includes:

- one login endpoint
- one current-user endpoint
- one representative protected endpoint
- one shared `401` response and one shared `403` response

Keep the documented path aligned with the real public route shape too:

If your app applies a global router prefix such as `/api`, include that in the OpenAPI `paths:` entry because clients call the public URL, not the prefix-free controller mapping.
If the public prefix is unclear, confirm it with `bash bin/console router:prefix` before you sync or edit route YAML.

## Recommended build order {#build-order}

### 1. Start after route and auth shape are stable {#build-stable-shape}

Documenting unstable routes too early creates churn.
First prove that the real HTTP path, request input object shape, and authentication choice already work.

### 2. Initialize the OpenAPI tree, then sync from controllers {#build-init-sync}

Start with [OpenAPI](https://github.com/switon-php/openapi/blob/main/docs/README.md) in its normal filesystem workflow.
Create the `openapi/` tree once, then let `open-api:sync` append missing route stubs from controller mappings.

### 3. Make the auth transport explicit in the contract {#build-auth-transport}

Choose the wording and schema that match the real caller boundary.
For cookie-backed auth, document the login/current-user flow as a session-oriented contract.
For bearer-token auth, document which endpoints issue tokens and which protected endpoints expect them.

### 4. Normalize shared deny responses and auth-facing schemas {#build-shared-responses}

Once more than one protected route exists, keep the first shared responses and reusable schemas in the top-level `components:` block, then split them into `openapi/components/*.yml` only when that makes the spec easier to own.
This keeps `401`, `403`, and current-user payloads consistent across operations.

### 5. Export and lint before handing the contract to others {#build-export-lint}

Treat `open-api:export` and `open-api:lint` as the finish line for the first documented boundary.
The goal is not “some YAML exists”; the goal is “the merged contract is coherent enough for a client to trust”.

## Minimal workflow {#minimal-workflow}

Use short examples only to show the seams between route discovery, shared auth responses, and protected operations.
Application endpoint names and response names below are illustrative. The stable part is the OpenAPI directory layout and command flow.

### Initialize and sync from routes {#workflow-init-sync}

Start with the normal CLI flow:

```bash
bash bin/console open-api:init @root/openapi
bash bin/console open-api:sync @root/openapi
bash bin/console open-api:export @root/openapi
bash bin/console open-api:lint @root/openapi --strict
```

After the first sync, edit the generated YAML instead of treating sync as a full YAML editor.

### Add shared auth responses {#workflow-auth-responses}

Keep repeated deny outcomes in one shared place:

```yaml
# openapi/openapi.yml
components:
  responses:
    auth.unauthorized:
      description: Caller is not authenticated.

    auth.forbidden:
      description: Caller is authenticated but not allowed to perform this action.
```

The response names above are app-owned example names. Keep your real names consistent with the rest of your spec.

### Enrich one protected operation {#workflow-protected-operation}

Then make one protected route explicit:

```yaml
# openapi/controllers/<prefix>.yml
paths:
  /me:
    get:
      operationId: <prefix>::me
      responses:
        '200':
          description: Current caller details.
        '401':
          $ref: '#/components/responses/auth.unauthorized'
        '403':
          $ref: '#/components/responses/auth.forbidden'
```

Let sync own the route discovery and initial `operationId` shape.
Your job is to enrich the contract: stable response meaning, reusable schemas, and clear auth-facing behavior.
If your app exposes the route behind a global prefix such as `/api`, document the public path as `/api/me` instead.

### Document the auth boundary, not just the happy path {#workflow-auth-boundary}

Keep the first contract explicit about which auth shape the client must follow:

- for [Session](https://github.com/switon-php/session/blob/main/docs/README.md)-style browser flows, describe the cookie-backed login/current-user expectation in operation text and response modeling
- for [JWT](https://github.com/switon-php/jwt/blob/main/docs/README.md)-style API flows, describe the bearer-token expectation on the protected operations that require it
- for [Authz](https://github.com/switon-php/authz/blob/main/docs/README.md)-protected routes, make deny semantics readable enough that clients know whether to re-authenticate or stop

## What to avoid first {#pitfalls}

Keep the first API-docs path intentionally narrow.

Avoid these too early:

- **Treating sync as a full YAML rewriter** — `open-api:sync` appends safe missing stubs; it does not take ownership of your whole spec
- **Inventing `operationId` labels that drift from handler ids** — keep the runtime-aligned shape so docs, routes, and permission-aware tooling stay consistent
- **Publishing an auth contract before auth is real** — stabilize login/current-user/protected routes first, then document them
- **Documenting every internal role or service detail** — the first contract should focus on client-visible routes, payloads, and deny outcomes
- **Turning the first docs path into browser UI or SDK platform work** — prove one clean contract before you widen tooling
- **Hand-maintaining every route before running sync once** — let the route-aware workflow create the baseline first

A beginner should feel this after finishing the first API-docs path:

> I can show a client how to authenticate, call one protected endpoint, and interpret `401` vs `403` without reverse-engineering controller code.

## Go deeper {#go-deeper}

- **To connect this after the first request path:** [Build an API](build-an-api.md#build-an-api) · [HTTP](https://github.com/switon-php/http/blob/main/docs/README.md)
- **To settle request-wide hooks, action wrappers, or failure policy before publishing the contract:** [Add cross-cutting HTTP behavior](add-cross-cutting-http-behavior.md#add-cross-cutting-http-behavior)
- **To connect this after identity and protection are already working:** [Add authentication](add-authentication.md#add-authentication)
- **To stay on the split-file workflow, export, and lint surface:** [OpenAPI](https://github.com/switon-php/openapi/blob/main/docs/README.md)
- **To inspect routes before sync or explain handler-id shapes:** [Router](https://github.com/switon-php/router/blob/main/docs/README.md)
- **To align protected operations with permission naming:** [Authz](https://github.com/switon-php/authz/blob/main/docs/README.md)
- **To document bearer-token callers precisely:** [JWT](https://github.com/switon-php/jwt/blob/main/docs/README.md)
- **To document cookie-backed login and current-user behavior:** [Session](https://github.com/switon-php/session/blob/main/docs/README.md) · [HTTP cookies](https://github.com/switon-php/http/blob/main/docs/cookies.md#cookies)
- **To automate export and lint in scripts or CI:** [CLI](https://github.com/switon-php/cli/blob/main/docs/README.md)
- **To ship the documented API with a stable runtime and worker-process shape:** [Deploy an app to production](deploy-an-app-to-production.md#deploy-an-app-to-production)
