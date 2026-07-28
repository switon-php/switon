# Guestbook Implementation Plan v2

## Summary

在 arcus 项目中实现留言板功能，支持用户发帖和查看列表。使用 Switon ORM 管理数据持久化，基于属性的路由和验证。

## Context

arcus 是 Switon 框架的应用骨架，已有 TimeController 和 IndexController 作为参考。留言板是最简单的 CRUD 场景，适合作为项目第一个业务功能。

本版更新：
- 数据库配置改用 SQLite 文件存储（`var/data.db`）
- 明确数据库初始化流程（自动建表）
- 简化数据库连接注册方式

## Approach

### 架构

- **实体层**：`app/Entity/GuestbookEntry.php` — 继承 `Switon\Orm\Entity`
- **仓储层**：`app/Repository/GuestbookRepository.php` — 继承 `Switon\Orm\Repository`
- **控制器层**：`app/Controller/GuestbookController.php` — 继承 `App\Controller\Controller`
- **数据库**：`var/data.db` — SQLite 文件数据库
- **初始化脚本**：`bin/init-db` — 创建表结构

### 路由设计

| Method | Path | Action | Description |
|--------|------|--------|------------|
| GET | /api/guestbook | listAction | 列表所有留言 |
| POST | /api/guestbook | createAction | 创建新留言 |

### 数据模型

**guestbook_entry 表**

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | INTEGER | PRIMARY KEY AUTOINCREMENT | 主键 |
| author | TEXT | NOT NULL | 留言者名称 |
| content | TEXT | NOT NULL | 留言内容 |
| created_at | TEXT | NOT NULL | 创建时间 (Y-m-d H:i:s) |

### 验证规则

- `author`：必填，长度 1-64
- `content`：必填，长度 1-2000

## Critical files

- `arcus/app/Entity/GuestbookEntry.php` — create — 留言实体类，使用 `#[Id]`，`#[Column]`，`#[Table]`，`#[CurrentTime]` 属性
- `arcus/app/Repository/GuestbookRepository.php` — create — 继承 `Switon\Orm\Repository`，自动推断实体类
- `arcus/app/Controller/GuestbookController.php` — create — 控制器类，使用 `#[Authorize(Authorize::ANONYMOUS)]` 允许匿名访问
- `arcus/bin/init-db` — create — 数据库初始化脚本，执行建表 SQL
- `arcus/switon.yml` — edit — 添加 `ClientInterface` 数据库连接配置
- `arcus/var/.gitkeep` — create — 确保 var 目录受版本控制

## Reuse

- 匿名路由 + JSON 响应：`arcus/app/Controller/TimeController.php:1-19` — 参照 `#[Authorize(Authorize::ANONYMOUS)]` + `#[RequestMapping('/time')]` + `#[GetMapping('current')]` 用法
- 控制器基类注入：`arcus/app/Controller/Controller.php:1-20` — 参照 `#[Autowired] protected RequestInterface $request` 用法
- ORM 实体属性：`packages/orm/tests/Fixtures/TestArticle.php` — 参照 `#[Id]` 属性
- 数据库连接注册：`packages/orm/tests/DatabaseTestCase.php:88-98` — 参照 SQLite URI 格式 `sqlite://test@:memory:` 和 Factory 用法
- 验证属性：`packages/validation/src/Attribute/Required.php` 和 `MaxLength.php` — 参照验证约束用法

## Verification

1. `bash bin/console router:list --json`
   **Pass:** 输出包含 `/api/guestbook` 的 GET 和 POST 路由

2. `bash bin/init-db`（首次运行）
   **Pass:** 输出 `Database initialized` 或 `Table already exists`

3. `GET /api/guestbook`
   **Pass:** HTTP 200，返回 JSON 数组 `{ "entries": [] }` 或包含留言列表

4. `POST /api/guestbook` with `{"author":"Test","content":"Hello"}`
   **Pass:** HTTP 201，返回创建的留言对象含 `id` 和 `created_at`

5. `POST /api/guestbook` with `{"author":"","content":""}`
   **Pass:** HTTP 422，返回验证错误 `{ "errors": { "author": "...", "content": "..." } }`

## Out of scope

- 留言编辑 / 删除功能
- 用户认证与关联
- 分页
- 前端页面