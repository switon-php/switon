# 环境准备 {#environment}

本机 shell 尚未就绪、还无法做 Switon 开发时使用本页。

最低目标（安装与首次证明）：

- `php -v` 可用
- `composer --version` 可用
- `git --version` 可用

满足后回到 [安装 Switon](install.md#install)。进入需要持久化、队列或缓存的指南前，再安装本地 MySQL、Redis。

---

## 支持的安装路径 {#supported-routes}

本文只写三条包管理器路径：

- macOS + `brew`
- Ubuntu + `apt`
- Windows + `scoop`

目标不是覆盖所有平台变体，而是让多数读者走上与文档其余部分一致、摩擦较低的一条路。

## macOS（brew）{#macos-brew}

```bash
brew install php@8.3 composer git mysql redis
```

确认 `php -v` 为 `PHP 8.3.x`。

## Ubuntu（apt）{#ubuntu-apt}

请显式安装 `PHP 8.3`，不要用浮动的 `php` 元包。

Ubuntu 24.04：

```bash
sudo apt update
sudo apt install -y php8.3 php8.3-cli php8.3-mysql php8.3-redis composer git mysql-server redis-server
```

Ubuntu 22.04 需先加 `ppa:ondrej/php`：

```bash
sudo add-apt-repository ppa:ondrej/php
sudo apt update
sudo apt install -y php8.3 php8.3-cli php8.3-mysql php8.3-redis composer git mysql-server redis-server
```

确认 `php -v` 为 `PHP 8.3.x`。

## Windows（scoop）{#windows-scoop}

```powershell
scoop bucket add php
scoop install php/php8.3 composer git mysql redis
```

确认 `php -v` 为 `PHP 8.3.x`。

## 验证 shell {#verify-the-shell}

```bash
php -v
composer --version
git --version
```

首次安装与 Hello World 只需 **PHP 8.3.x、Composer、Git**。许多后续指南还会用到本地 MySQL、Redis——在开始持久化、队列、缓存等路径前再装即可。
