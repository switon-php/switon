---
description: Start with local setup, first proof, and the fastest path into the right Switon docs.
---

# Getting started {#getting-started}

## Default route {#default-route}

1. [Environment setup](environment.md#environment)
2. [Install Switon](install.md#install)
3. [First Hello World checklist](first-hello-world.md#first-hello-world)
4. [Tutorial](../tutorial/README.md#tutorial) or [Guides](../guides/README.md#guides)

If you are still new after the first successful proof, prefer [Tutorial](../tutorial/README.md#tutorial) before [Packages](../packages.md#packages).
If you are unsure between [Tutorial](../tutorial/README.md#tutorial) and [Guides](../guides/README.md#guides), choose `Tutorial` first.

## Environment goal {#environment-goal}

The minimum shell target for the first install and proof is simple:

- `php -v` works
- `composer --version` works
- `git --version` works

Use [Environment setup](environment.md#environment) to reach that state through one supported package-manager route:

- macOS with `brew`
- Ubuntu with `apt`
- Windows with `scoop`

Add local `MySQL` and `Redis` when the guide you are following actually needs them, such as persistence, queue, or cache work.
