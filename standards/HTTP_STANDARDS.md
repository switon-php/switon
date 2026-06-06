# HTTP Standards

Applies to framework HTTP work under `packages/http/*` and direct integration points.
Use this file for stable request/response authoring rules.
Component-specific tracing paths belong in PHPDoc `Road-signs`, not here.

## Scope

- Covers request lifecycle boundaries, action return normalization, exception convergence, response finalization, and redirect/short-circuit rules.
- This file defines stable HTTP authoring contracts, not every current implementation detail.
- Troubleshooting sequences and multi-hop runtime tracing should stay in PHPDoc `Road-signs` on the relevant entry classes.

---

## Context-backed request/response state (MANDATORY)

- Treat `Request` and `Response` as context-backed facades.
- Keep mutable HTTP state in request/response context objects, not in service properties.
- Do not hide per-request mutable state inside filters, handlers, or helper services.

---

## Lifecycle extension boundaries (MANDATORY)

- Attach HTTP extensions to stable lifecycle stages.
- Use request-stage events for request mutation, routing, auth(z), validation, invocation, rendering, and response finalization.
- Do not couple business logic to temporary implementation steps when a stable lifecycle event already exists.
- Prefer lifecycle listeners for observability concerns such as request ID, access log, and slowlog.

---

## Request input semantics (MANDATORY)

- Keep transport input, request adjustment, body parsing, and route-variable merge as separate stages.
- Request input precedence is `route > POST > GET`.
- Do not rely on earlier transport snapshots once route variables or parsed body have replaced them.

---

## Action return normalization (MANDATORY)

- Return `ResponseInterface` when the action needs full response control.
- Otherwise rely on the framework normalization path for common action return values.
- Keep action return conventions stable across controllers.
- Do not mix ad-hoc controller payload shapes when the normalization transformer already owns that contract.

---

## Exception convergence (MANDATORY)

- Let request-pipeline failures converge through `ExceptionDispatcher` and exception handlers.
- Do not scatter controller- or filter-level catch-and-render logic when the central HTTP exception path should own the result.
- Keep handler selection semantics specific-first and fallback-last.

---

## Response finalization (MANDATORY)

- Treat `ResponseAdjust`, `ResponseStringify`, and `RequestEnd` as response-finalization boundaries.
- Keep listeners on those stages no-throw whenever possible.
- Use these stages for final shaping, headers, observability, and light response adjustments.
- Do not move complex business decisions into response-finalization listeners.

---

## Redirect and short-circuit flow (MANDATORY)

- In HTTP, `StopFlow` represents intentional short-circuit control flow.
- Use `Response::redirect()` as the standard redirect expression.
- Short-circuiting still proceeds through unified response finalization.
- Do not use `StopFlow` as a substitute for ordinary failures.

---

## Authoring vs navigation boundary

- Put stable writing rules in standards.
- Put request-tracing sequences, exception hop paths, and response-finalization debugging paths in PHPDoc `Road-signs` on `RequestHandler`, `ExceptionDispatcher`, `Response`, and related entry classes.
- If a rule mainly answers “where do I look next?”, it belongs in `Road-signs` before it belongs here.

---

## Review checklist

- Request/response mutable state stays in contexts.
- Extensions attach to stable lifecycle events.
- Input precedence remains `route > POST > GET`.
- Controllers rely on the shared action-return contract unless they intentionally return `ResponseInterface`.
- Exceptions converge through the central HTTP dispatcher/handler path.
- Response-finalization listeners stay lightweight and no-throw.
- Component-specific tracing guidance lives in `Road-signs`, not in this standard.
