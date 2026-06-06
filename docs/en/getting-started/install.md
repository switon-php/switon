# Install Switon {#install}

Use this page only for one job: create a fresh Switon app from `switon/skeleton`.

---

## Prerequisites {#prerequisites}

Before this step:

- `php -v` works
- `composer --version` works
- `git --version` works

If your shell is not ready yet, stop here and use [Environment setup](environment.md#environment).

## Create the app {#create-the-app}

Create a fresh app from the skeleton package when it is available in your Composer repositories:

```bash
composer create-project switon/skeleton my-app
cd my-app
```

If `composer create-project` cannot find `switon/skeleton`, the skeleton has not been published to the Composer repository your environment uses yet. Use the repository-provided skeleton source or configure the package repository first, then rerun the command.

That gives you a ready-made app boundary with HTTP and CLI entrypoints:

- `bin/console`
- `public/index.php`
- `app/`
- `switon.yml`
- `config/` (optional PHP-only configuration patches)

If you only want the first successful proof now, continue with [First Hello World checklist](first-hello-world.md#first-hello-world).
