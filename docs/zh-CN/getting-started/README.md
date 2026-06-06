---
description: 本地环境、首次安装与 Hello World 证明，尽快进入教程或任务指南。
---

# 入门 {#getting-started}

## 默认路线 {#default-route}

1. [环境准备](environment.md#environment)
2. [安装 Switon](install.md#install)
3. [首次 Hello World 清单](first-hello-world.md#first-hello-world)
4. [教程](../tutorial/README.md#tutorial) 或 [指南](../guides/README.md#guides)

首次验证完成后，如果你还偏新手，优先选 **教程**，不要先看 [包索引](../packages.md#packages)。在教程和指南之间犹豫时，**先选教程**。

## 环境目标 {#environment-goal}

首次安装与证明的最低 shell 要求：

- `php -v` 能正常运行
- `composer --version` 能正常运行
- `git --version` 能正常运行

见 [环境准备](environment.md#environment)，路径分别是 macOS `brew`、Ubuntu `apt`、Windows `scoop`。只有当你要跟的指南确实需要持久化、队列或缓存时，再安装本地 MySQL 和 Redis。
