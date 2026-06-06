# Conventions

本文件定义 Arcus schema 的统一约定，避免后续口径漂移。

## Naming

- 表名：英文单数，`snake_case`
- 主键：`id`（`INT UNSIGNED`）
- 时间：`*_at`（UTC 秒）
- 人员：`*_by`（逻辑关联 `user.id`）
- 多态：`target_type + target_id`（`target_type` 为物理表名）

## Null and Defaults

- 一般不用 `NULL`
- 字符串默认 `''`
- 数值默认 `0`
- JSON 默认 `[]` 或 `{}`（项目内固定一种）

## Visibility and Listing

- `visibility`：控制访问范围（公开/内部/私密）
- `unlisted`：控制是否参与列表曝光
- `deleted + recycled_at`：回收站语义

## Publishing

- `published_id`：当前已发布快照指针
- `published_at`：对外生效时间锚
- `publish_schedule`：未来发布队列（JSON）

## Integrity Rules

- 不设 DB 外键，应用层负责完整性检查。
- `_id` 写入时必须校验目标实体存在且类型匹配。
- 关键状态更新（发布/撤回/回收站）应在同一事务边界完成。
