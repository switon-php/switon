# First Hello World checklist {#first-hello-world}

Fast proof that a fresh Switon app is really running before you start the tutorial or task guides. Use `switon/skeleton` when your Composer repositories provide it.

## The shortest runnable path {#quick-path}

Prerequisites: `php`, `composer`, and `git` work locally. If not, fix that in [Environment setup](environment.md#environment) first.

### 1. Create the app {#step-create}

```bash
composer create-project switon/skeleton my-app
cd my-app
```

Expected: the project exists; the app root has `bin/console`, `public/index.php`, `app/`, and `switon.yml` (with an optional `config/` directory for PHP-only patches). If Composer cannot find `switon/skeleton`, fix the repository source before continuing.

### 2. Prove the CLI entry works {#step-cli}

```bash
bash bin/console list
```

Expected: the command list prints successfully.

### 3. Prove the router is loaded {#step-router}

```bash
bash bin/console router:list
bash bin/console router:prefix
```

Expected: routes are listed; `router:prefix` prints the active public prefix (often `/api` on the published skeleton).

### 4. Start the local HTTP entry {#step-http}

```bash
SERVER_TYPE=php php public/index.php
```

Expected: the local server starts (often `http://127.0.0.1:9501`). Keep it running for the next step.

### 5. Prove the HTTP path responds {#step-http-check}

Open these URLs in the browser, or call them with `curl`:

```bash
curl http://127.0.0.1:9501/api/
curl http://127.0.0.1:9501/api/time/current
```

Expected: `/api/` returns the welcome payload; `/api/time/current` returns a routed response under the same prefix.

### 6. Stop here {#step-stop}

If the steps above work, you have a valid first baseline. You do **not** need database, authentication, caching, or HTML templates for this proof.

Confirm `router:prefix` before guessing URLs in the browser.
