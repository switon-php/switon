# Tables Core

本文件只保留核心表的稳定设计要点，详细字段解释可在主稿追溯。

## Content

### `post`

- 主内容实体
- 关键列：`slug`、`title`、`body`、`category_id`
- 发布列：`published_id`、`published_at`、`publish_schedule`
- 访问列：`visibility`、`unlisted`、`visit_password`
- 生命周期列：`deleted`、`recycled_at`

### `page`

- 与 `post` 同构，独立为页面模型
- 关键差异：`parent_id` 支持页面层级

### `snapshot`

- 快照统一版本表
- 关键列：`uuid`、`target_type`、`target_id`、`raw_type`、`raw_patch`、`parent_id`
- 仅保留一套载荷，避免重复 patch 字段

## Taxonomy

### `taxonomy`

- 分类体系命名空间

### `category`

- 树结构分类（`parent_id`）
- 同体系唯一：`(taxonomy_id, slug)`

### `tag`

- 扁平标签
- `taxonomy_id=0` 代表全局标签

## Interaction

### `comment`

- 通过 `target_type + target_id` 挂接宿主内容

### `reply`

- 通过 `comment_id` 归属评论
- `quoted_reply_id` 表示引用关系

## Supporting

- `attachment`：媒体对象
- `meta`：扩展键值
- `setting`：站点级配置
