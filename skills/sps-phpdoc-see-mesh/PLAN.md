# @see mesh — per-package execution plan

Execute one package at a time. For each package:

1. **Audit**: List interfaces, main implementations, exceptions, events; check existing @see (FQCN, no circular, per-class targets from SKILL).
2. **Fix**: Add missing @see; impl→interface, interface→default impl; exception→thrower; event→emitter/listener; cross-package implements/extends.
3. **Verify**: Quick check (from concrete→interface+DTO? from interface→impl+payload? cross-package subclass @see parent?).

Package order (core/hub first, then by dependency layer):

| # | Package    | Status | Notes |
|---|------------|--------|-------|
| 1 | core       | [x]    | Contracts; each interface @see default impl if in other pkg |
| 2 | di         | [x]    | ContainerInterface, FactoryInterface; cross-pkg impls |
| 3 | event      | [x]    | Event flow: dispatcher, events, typical listeners |
| 4 | kernel     | [x]    | App bootstrap, config; @see config reader class |
| 5 | context    | [x]    | ContextAware, request-scoped state |
| 6 | cache      | [x]    | CacheInterface ↔ SimpleCache / adapters |
| 7 | cli        | [x]    | Kernel, commands; @see Core where used |
| 8 | db         | [x]    | Connection, Factory; events, exceptions |
| 9 | http       | [x]    | Kernel, events (RequestBegin etc.), filters and transformers |
| 10| http-client| [x]    | Client interface, impl |
| 11| i18n       | [x]    | Translator, loaders |
| 12| identity   | [x]    | IdentityInterface; @see Core + consumers |
| 13| invoker    | [x]    | Callable resolution |
| 14| log        | [x]    | LoggerInterface, Logger, Appender, events |
| 15| pool       | [x]    | PoolableConnection, pool impl |
| 16| redis      | [x]    | Client, Factory; events, exceptions |
| 17| router     | [x]    | RouterInterface, matcher, route types |
| 18| session    | [x]    | Session interface, handlers |
| 19| renderer   | [x]    | RendererInterface, engines |
| 20| validation  | [x]    | Validator, rules |
| 21| authz      | [x]    | LookupInterface, policies |
| 22| rbac       | [x]    | Rbac service, Orm integration |
| 23| captcha    | [x]    | Abstract ↔ GdAdapter, ImagickAdapter |
| 24| crypto     | [x]    | Cipher, key interfaces |
| 25| id         | [x]    | Id generator interfaces |
| 26| jwt        | [x]    | JWT encode/decode |
| 27| throttle   | [x]    | RateLimiter, strategies |
| 28| view       | [x]    | ViewRenderer, renderer integration |
| 29| http-cache | [x]    | Filter, cache integration |
| 30| inspector  | [x]    | Collectors, storage |
| 31| orm        | [x]    | Entity, Repository; @see Core/Di |
| 32| query      | [x]    | Query builder |
| 33| sharding   | [x]    | Shard selection |
| 34| sync       | [x]    | Lock, mutex |
| 35| composer-plugin | [x] | Plugin entry |

**Status**: `[ ]` todo, `[x]` done. When working a package, set it to in progress in the plan and run audit → fix → verify; then mark `[x]`.

**Scope**: Only `packages/<name>/` (and cross-package @see to/from other Switon packages). Follow SKILL.md rules; do not @see internal utils, every single impl, or transitive deps.
