# Learning Mainline Proof App

Runnable proof app for the English learning mainline baseline.

This app exists to prove the shared beginner baseline used across `docs/en/tutorial`, `docs/en/guides`, and `docs/en/getting-started` with a controlled, versioned asset.
It is not an application skeleton and does not depend on `packages/skeleton/vendor/`.

## What it covers

- HTTP routes under `/api/tasks`
- HTTP completion route under `/api/tasks/{id}/complete`
- request DTO binding with validation
- service boundary with `#[Autowired]`
- ORM-backed task persistence on SQLite
- CLI reuse through `task:complete`
- stable app entrypoints for doc-proof checks

## Quick start

From the repository root:

```bash
cd tests/doc-proofs/tutorial-mainline-app
bash bin/console task:setup
bash bin/console router:list --json
bash bin/console list --json
SERVER_TYPE=php php public/index.php
```

Then try:

```bash
curl http://127.0.0.1:9501/api/tasks
curl -X POST http://127.0.0.1:9501/api/tasks \
  -H 'Content-Type: application/json' \
  -d '{"title":"Ship learning mainline proof app","note":"wired through DTO + service + ORM"}'
curl -X PUT http://127.0.0.1:9501/api/tasks/1/complete
bash bin/console task:complete 1
```

## Notes

- SQLite file: `database/tutorial.sqlite`
- Schema source: `database/schema.sql`
- bootstrap file: `bootstrap/autoload.php`
- `task:setup --reset` recreates the SQLite file and reseeds one demo task
