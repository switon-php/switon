# Environment Setup {#environment}

Use this page when the local shell is not ready yet for Switon development.

The minimum target state for install and first proof is:

- `php -v` works
- `composer --version` works
- `git --version` works

Once those commands work, go back to [Install Switon](install.md#install).

Add local `MySQL` and `Redis` later when you move into guides that actually need them.

---

## Supported routes {#supported-routes}

This page only documents three package-manager paths:

- macOS with `brew`
- Ubuntu with `apt`
- Windows with `scoop`

The point is not to cover every platform variation. The point is to guide most readers onto one low-friction path that matches the rest of the docs.

## macOS with `brew` {#macos-brew}

Install the local development dependencies:

```bash
brew install php@8.3 composer git mysql redis
```

Then verify that `php -v` reports `PHP 8.3.x`.

## Ubuntu with `apt` {#ubuntu-apt}

Install `PHP 8.3` explicitly instead of the floating `php` meta package.

If you are on Ubuntu `24.04`, the default archive already provides `php8.3`:

```bash
sudo apt update
sudo apt install -y php8.3 php8.3-cli php8.3-mysql php8.3-redis composer git mysql-server redis-server
```

If you are on Ubuntu `22.04`, add `ppa:ondrej/php` first so `apt` can install `php8.3`:

```bash
sudo add-apt-repository ppa:ondrej/php
sudo apt update
sudo apt install -y php8.3 php8.3-cli php8.3-mysql php8.3-redis composer git mysql-server redis-server
```

Then verify that `php -v` reports `PHP 8.3.x`.

## Windows with `scoop` {#windows-scoop}

Install the `php` bucket first, then install the `8.3` package explicitly:

```powershell
scoop bucket add php
scoop install php/php8.3 composer git mysql redis
```

Then verify that `php -v` reports `PHP 8.3.x`.

## Verify the shell {#verify-the-shell}

Run these checks:

```bash
php -v
composer --version
git --version
```

For the first install and Hello World path, the only thing that matters is that these three pieces are ready:

- `PHP 8.3.x`
- `Composer`
- `Git`

Many later guides also expect local `MySQL` and `Redis`.
Add them before you start persistence, queue, cache, or other infrastructure-heavy paths.
