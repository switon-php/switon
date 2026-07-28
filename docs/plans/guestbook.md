# Guestbook Implementation Plan

## Summary

在 arcus 项目中实现留言板功能，支持用户发帖和查看列表。使用 Switon ORM 管理数据持久化，基于属性的路由和验证。

## Context

arcus 是 Switon 框架的应用骨架，已有 TimeController 和 IndexController 作为参考。留言板是最简单的 CRUD 场景，适合作为项目第一个业务功能。

## Approach

### 架构

- **实体层**：`app/Entity/GuestbookEntry.php` — 继承 `Switon\Orm\Entity`
- **仓储层**：`app/Repository/GuestbookRepository.php` — 继承 `Switon\Orm\Repository`
- **控制器层**：`app/Controller/GuestbookController.php` — 继承 `App\Controller\Controller`
- **配置层**：`switon.yml` 或 `config/` — 声明数据库连接

### 路由设计

| Method | Path | Action | Description |
|--------|------|--------|------------|
| GET | /api/guestbook | listAction | 列表所有留言 |
| POST | /api/guestbook | createAction | 创建新留言 |

### 数据模型

**guestbook_entry 表**

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | INT | PRIMARY KEY AUTO_INCREMENT | 主键 |
| author | VARCHAR(64) | NOT NULL | 留言者名称 |
| content | TEXT | NOT NULL | 留言内容 |
| created_at | VARCHAR(19) | NOT NULL | 创建时间 (Y-m-d H:i:s) |

### 验证规则

- `author`：必填，长度 1-64
- `content`：必填，长度 1-2000

## Critical files

- `arcus/app/Entity/GuestbookEntry.php` — create — 留言实体类，使用 `#[Id]`、`#[Column]`、`#[Table]`、`#[CurrentTime]` 属性
- `arcus/app/Repository/GuestbookRepository.php` — create — 继承 `Switon\Orm\Repository`，仓储基类自动注入 entityManager 和 queryBuilder
- `arcus/app/Controller/GuestbookController.php` — create — 控制器类，使用 `#[Authorize(Authorize::ANONYMOUS)]` 允许匿名访问
- `arcus/config/database.php` — create — 数据库连接配置（SQLite 示例）
- `arcus/.env` — edit — 添加数据库连接环境变量

## Reuse

- 匿名路由 + JSON 响应：`arcus/app/Controller/TimeController.php:1-19` — 参照 `#[Authorize(Authorize::ANONYMOUS)]` + `#[RequestMapping('/time')]` + `#[GetMapping('current')]` 用法
- 实体属性定义：`packages/orm/tests/Fixtures/TestArticle.php` — 参照 `#[Id]`、`#[Column]`、`#[Table]` 属性
- 控制器基类注入：`arcus/app/Controller/Controller.php:1-20` — 参照 `#[Autowired] protected RequestInterface $request`
- 数据库测试用例：`packages/orm/tests/Integration/RepositoryIntegrationTest.php:55-160` — 参照 SQLite 内存数据库建表和 insert/assertDatabaseHas 用法

## Verification

1. `bash bin/console router:list --json`
   **Pass:** 输出包含 `/api/guestbook` 的 GET 和 POST 路由

2. `GET /api/guestbook`
   **Pass:** HTTP 200，返回 JSON 数组 `{ "entries": [] }` 或包含留言列表

3. `POST /api/guestbook` with `{"author":"Test","content":"Hello"}`
   **Pass:** HTTP 201，返回创建的留言对象含 `id` 和 `created_at`

4. `POST /api/guestbook` with `{"author":"","content":""}`
   **Pass:** HTTP 422，返回验证错误 `{ "errors": { "author": "...", "content": "..." } }`

## Out of scope

- 留言编辑 / 删除功能
- 用户认证与关联
- 分页
- 前端页面
