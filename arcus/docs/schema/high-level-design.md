# 概要设计

本稿用于把 schema 设计转成可实施的系统设计边界，作为功能设计前置输入。

## 1. 设计目标

- 保持内容系统最小可用闭环：编辑、定时发布、前台可见、回收站。
- 保持字段语义单一：一个字段只回答一个问题，避免状态重叠。
- 保持主路径可查询：列表、详情、发布任务扫描都可走清晰索引。

## 2. 系统边界

- **数据层**：关系模型定义在 `post/page/snapshot/comment/...`。
- **领域层**：发布、撤回、回收站等状态变迁由领域方法统一驱动。
- **任务层**：定时任务只消费 `publish_schedule`，不写业务分支规则。
- **接口层**：对外 API 不暴露内部实现细节（内部主键、链路细节）。

## 3. 核心对象与职责

- **`post` / `page`**：对外内容宿主，承载投影字段与访问门槛字段。
- **`snapshot`**：版本源数据，承载草稿链和发布目标节点。
- **`publish_schedule`**：未来发布计划队列（`at + snapshot_id`）。
- **`category/tag`**：信息架构与检索维度，不承担发布状态。

## 4. 发布状态模型

发布判定由组合规则完成，不引入额外状态列。

- `published_id`：当前线上版本指针（发布哪条）。
- `published_at`：发布时间锚（何时可见）。
- `publish_schedule`：未来任务队列（之后发布什么）。

前台可见需满足：

- `published_id > 0`
- `published_at > 0 && published_at <= now`
- `deleted = 0`
- `visibility` 命中访问策略
- `visit_password` 校验通过（若设置）

## 5. 标准查询口径

- **前台详情**：按 `id/slug` 定位后应用完整可见性规则。
- **前台列表**：在详情规则基础上增加 `unlisted = 0`。
- **后台列表**：不套前台可见规则，仅做管理维度筛选。
- **定时扫描**：从 `publish_schedule` 读取 `at <= now` 项并事务发布。

## 6. 一致性与事务边界

以下字段必须同事务更新：

- `published_id`
- `published_at`
- 宿主投影字段（`body` 等）
- `publish_schedule`（删除已执行项）

约束要求：

- `snapshot.parent_id != snapshot.id`
- 快照链必须防成环
- `published_id` 与 `publish_schedule[].snapshot_id` 必须匹配宿主 `target_*`

## 7. 索引基线

- `post.slug`（UNIQUE）
- `post(category_id)`
- `post(published_at)`
- `post(published_id)`
- `post(deleted, recycled_at)`
- `snapshot(target_type, target_id)`
- `snapshot(parent_id)`
- `snapshot(uuid)`（UNIQUE）

## 8. 功能设计输入清单

进入功能设计前，需先冻结以下项：

- 查询口径（详情/列表/后台/定时）不再变更。
- 发布事务写入字段集合不再变更。
- 命名集冻结（`unlisted`、`parent_id`、`publish_schedule` 等）。
- migration 首版字段与索引清单冻结。

## 9. 技术约束与运行环境

本节只定义会影响架构决策的技术约束，不展开实现细节。

- 后端框架：`Switon`
- 前端策略：采用免编译模式（MPA + 模板渲染 + 静态 JS/CSS）
- 前端实现：参考 `app-admin`，使用浏览器直载脚本（如全局 `Vue` + `axios`），不引入 Vite/Nuxt 构建链
- 后端接口：以 HTTP API 为主，遵守统一鉴权与分页约定
- 数据库：关系型数据库（按 migration 目标环境确定方言）
- 定时任务：服务端任务调度消费 `publish_schedule`
- 时区策略：统一使用 UTC（写入与比较一致）
- 部署模型：支持多实例并发，发布任务需避免重复消费
- SEO 约束：当前阶段不作为列表页设计阻塞项
