# Table and entity list

- Table ↔ entity。详细列定义见 **[halo-traditional-schema.md](halo-traditional-schema.md)**（**列名优先常见 CMS**，Halo 写在列说明）。**表名英文单数**（如 **`user`、`post`**）。主键与关联列用 **`INT UNSIGNED`**（一般不用 **`BIGINT`**）；**不设数据库 `FOREIGN KEY`**，由代码维护引用完整性。**`VARCHAR` 须带长度**，梯度见该文档 §1。**不建** Halo 式 **`metadata.name`** 对应列；**`user.username`**、**`user.nickname`**、内容 **`slug`** 等见该文档 §1。**`post` / `page`** **不设宿主 `uuid`**；多态与快照挂宿主用 **`target_type`（=表名）+ `target_id`**（§1）。**`snapshot`** **每行 `uuid`**：**对外** API/链接 **只用 `uuid`**，库内 **`published_id` / `draft_id`** 仍指向 **`snapshot.id`**（§3.9）。其余表若还要 **`public_token`** 等，见 schema §1。时间 **`_at`**、人 **`_by`**；有 **`updated_at`** 的表 **成对** 设 **`updated_by`**（与 **`created_at` / `created_by`** 同约定）；真假 **`TINYINT`（0/1）**；**一般不用 `NULL`**，`''` / **`0`** — 见该文档 §1。
- Read before create/change。`—` = no entity。
- Update when adding or removing。

| Table                   | Entity                       | Notes                          |
| ----------------------- | ---------------------------- | ------------------------------ |
| `user`                  | `App\Entity\User`（待定）        | 全站唯一人员表；**`username` `VARCHAR(32)` `UNIQUE`**、**`nickname`** 展示名（Halo `displayName`）；无单独 `admin` 表 |
| `rbac_*`                | `App\Areas\Rbac\Entity\*`（参考 **app-admin**） | 表：`rbac_role`、`rbac_permission`、`rbac_role_permission`、`rbac_user_role`、`rbac_admin_role`；**不建** Halo 式 `role` / `role_binding` |
| `taxonomy`              | `App\Entity\Taxonomy`（待定）    | 多套并列**命名空间**；与 `category`/`tag` 关系见 schema **§3.3「重要」** |
| `category`              | `App\Entity\Category`（待定）    | 必 **`taxonomy_id`**；**`parent_id`** 树；`slug` 在 **(`taxonomy_id`, `slug`)** 内唯一 |
| `tag`                   | `App\Entity\Tag`（待定）         | **`taxonomy_id=0`** 全局标签；**非 0** 与某 `taxonomy` 分桶；详见 **§3.3「重要」** |
| `post`                  | `App\Entity\Post`（待定）        | **`body`** 已发布正文投影（**`copyOnPublish`** 写回）；**`category_id`** 主栏目；**`post_category`** 仅附加栏目；**`published_id` / `draft_id`** → `snapshot.id`；其余见 schema |
| `meta`                  | —                            | 多态扩展字段（`object_type`+`object_id`+`meta_key`），对齐 WP meta |
| `setting`               | —                            | 站点选项，对齐 WP `options`；含 **`autoload`** |
| `post_category`         | —                            | **仅附加**栏目（不含 **`post.category_id`**） |
| `page_category`         | —                            | **仅附加**；主栏目在 **`page.category_id`** |
| `post_tag`              | —                            | 多对多                            |
| `page_tag`              | —                            | `page` ↔ `tag`；未用可不建            |
| `page`                  | `App\Entity\Page`（待定）      | 对齐 Halo **`SinglePage`**；与 `post` 同构（含 **`body`**）+ **`parent_id`**；**`category_id`** + 可选 **`page_category`** |
| `snapshot`              | `App\Entity\Snapshot`（待定）    | **`target_type` + `target_id`** 挂内容；**`parent_snapshot_id`** → `snapshot.id`（**`0`**=无）；载荷在 `raw_patch/content_patch` 中按字段稀疏继承/覆盖；发布时按投影域把合并结果复制回宿主表 |
| `comment`               | `App\Entity\Comment`（待定）     | **`target_*` + `author_*`**，见 schema |
| `reply`                 | `App\Entity\Reply`（待定）       | **`quoted_reply_id`** → `reply.id`（**`0`**=无；同 **`comment_id`** 下） |
| `attachment`            | `App\Entity\Attachment`（待定）  | **`storage_key`** + **`permalink`**；终局表；媒体功能可分期交付 |

