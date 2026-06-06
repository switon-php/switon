# 安装 Switon {#install}

本页只做一件事：用 `switon/skeleton` 创建一个全新的 Switon 应用。

---

## 前提 {#prerequisites}

开始前请确认：

- `php -v` 可用
- `composer --version` 可用
- `git --version` 可用

若 shell 尚未就绪，请先 [环境准备](environment.md#environment)。

## 创建应用 {#create-the-app}

当你的 Composer 源里已有 skeleton 时：

```bash
composer create-project switon/skeleton my-app
cd my-app
```

若找不到 `switon/skeleton`，说明当前 Composer 仓库尚未发布该包——请按仓库说明配置源或使用提供的 skeleton 源码后再执行。

你将得到带 HTTP/CLI 入口的应用边界：

- `bin/console`
- `public/index.php`
- `app/`
- `switon.yml`
- `config/`（可选，仅用于 PHP-only 配置补丁）

若只想完成首次证明，继续 [Hello World 清单](first-hello-world.md)。
