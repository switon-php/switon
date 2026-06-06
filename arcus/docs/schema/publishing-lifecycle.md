# Publishing and Lifecycle

本文件定义发布链路与标准查询口径，供功能设计直接复用。

## Publish Flow

1. 编辑生成/更新 `snapshot`
2. 立即发布或写入 `publish_schedule`
3. 到点任务将目标 `snapshot_id` 提升为 `published_id`
4. 同步更新 `published_at` 与宿主投影字段

## Visibility Rule

对外可见需同时满足：

- `published_id > 0`
- `published_at > 0`
- `published_at <= now`
- `deleted = 0`
- `visibility` 与当前访问身份匹配
- 若设置 `visit_password`，需通过口令校验

## Standard Query Profiles

### Frontend Detail

- 主键或 slug 定位内容
- 应用完整可见性规则

### Frontend List

- `deleted = 0`
- `unlisted = 0`
- 应用发布与可见性规则
- 常见排序：`pinned DESC, published_at DESC, id DESC`

### Backend List

- 不强制前台可见规则
- 支持按发布态、回收站态、栏目、关键字过滤

### Scheduler Scan

- 读取 `publish_schedule` 中 `at <= now` 的项
- 按事务执行发布并移除已执行项

## Transaction Boundary

以下字段应同事务更新：

- `published_id`
- `published_at`
- 宿主投影内容字段（如 `body`、`list_excerpt` 若保留）
- `publish_schedule`（删除已执行任务）
