# Guestbook Implementation Plan v3

## Summary

在 arcus 项目中实现留言板功能，支持用户发帖和查看列表。使用 Switon ORM 管理数据持久化，基于属性的路由和验证。

## Context

arcus 是 Switon 框架的应用骨架，已有 TimeController 和 IndexController 作为参考。留言板是最简单的 CRUD 场景，适合作为项目第一个业务功能。

本版更新（v3）：
- 数据库配置改用 `Switon\Di\Factory` 注册 `ClientInterface`，URI 格式为 `sqlite:\/\/\/var\/data.db`
- 验证步骤使用具体 curl 命令和预期响应
- init-db 脚本直接用 PDO 执行 SQL

## Approach

### 架构

- **实体层**：`app/Entity/GuestbookEntry.php` — 继承 `Switon\Orm\Entity`
- **仓储层**：`app/Repository/GuestbookRepository.php` — 继承 `Switon\Orm\Repository`
- **控制器层**：`app/Controller/GuestbookController.php` — 继承 `App\Controller\Controller`
- **数据库**：`var/data.db` — SQLite 文件数据库
- **初始化脚本**：`bin/init-db` — 使用 PDO 执行建表 SQL

### 路由设计

| Method | Path | Action | Description |
|--------|------|--------|-------------|
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

- `arcus/app/Entity/GuestbookEntry.php` — create — 留言实体类，使用 `#[Id]`、`#[Column]`、`#[Table]`、`#[CurrentTime]` 属性
- `arcus/app/Repository/GuestbookRepository.php` — create — 继承 `Switon\Orm\Repository`，自动推断实体类
- `arcus/app/Controller/GuestbookController.php` — create — 控制器类，使用 `#[Authorize(Authorize::ANONYMOUS)]` 允许匿名访问
- `arcus/bin/init-db` — create — 数据库初始化脚本，使用 PDO 执行 CREATE TABLE IF NOT EXISTS
- `arcus/switon.yml` — edit — 添加 `Switon\Db\ClientInterface` 数据库连接配置，使用 `Switon\Di\Factory`
- `arcus/var/.gitkeep` — create — 确保 var 目录受版本控制

## Reuse

- 匿名路由 + JSON 响应：`arcus/app/Controller/TimeController.php:1-19` — 参照 `#[Authorize(Authorize::ANONYMOUS)]` + `#[RequestMapping('\/time')]` + `#[GetMapping('current')]` 用法
- 控制器基类注入：`arcus/app/Controller/Controller.php:1-20` — 参照 `#[Autowired] protected RequestInterface $request` 用法
- ORM 实体属性：`packages/orm/tests/Fixtures/TestArticle.php` — 参照 `#[Id]`、`#[Column]`、`#[Table]` 属性
- ORM 测试数据库配置：`packages/orm/tests/DatabaseTestCase.php:88-98` — 参照 `Switon\Di\Factory` 用法和 SQLite URI 格式
- 验证属性：`packages/validation/src/Attribute/Required.php` 和 `MaxLength.php` — 参照验证约束用法
- SQLite 文件路径解析：`packages/db/src/Connection/Adapter/Sqlite.php:27-45` — URI 格式 `sqlite:\/\/\/var\/data.db` 会被解析为 `sqlite:<resolved_path>`

## Verification

### 前置准备

```bash
cd /Users/mark/www/switon/arcus
```

1. `bash bin/init-db`
   **Pass:** 输出 `Database initialized` 或 `Table already exists`，且 `var/data.db` 文件存在

2. `curl -s http://localhost:$(grep SERVER_PORT .env 2>/dev/null | cut -d= -f2 || echo 9501)/api/guestbook`
   **Pass:** HTTP 200，返回 JSON：
   ```json
   {"entries":[]}
   ```

3. `curl -s -X POST http://localhost:$(grep SERVER_PORT .env 2>/dev/null | cut -d= -f2 || echo 9501)/api/guestbook -H "Content-Type: application/json" -d '{"author":"Test User","content":"Hello World"}'`
   **Pass:** HTTP 201，返回 JSON 包含：
   - `id`: 整数
   - `author`: "Test User"
   - `content`: "Hello World"
   - `created_at`: "YYYY-MM-DD HH:MM:SS" 格式

4. `curl -s http://localhost:$(grep SERVER_PORT .env 2>/dev/null | cut -d= -f2 || echo 9501)/api/guestbook`
   **Pass:** HTTP 200，返回 JSON：
   ```json
   {"entries":[{"id":1,"author":"Test User","content":"Hello World","created_at":"..."}]}
   ```

5. `curl -s -X POST http://localhost:$(grep SERVER_PORT .env 2>/dev/null | cut -d= -f2 || echo 9501)/api/guestbook -H "Content-Type: application/json" -d '{"author":"","content":""}'`
   **Pass:** HTTP 422，返回 JSON 包含 `errors` 字段，结构为：
   ```json
   {"errors":{"author":"...","content":"..."}}
   ```

6. `curl -s -X POST http://localhost:$(grep SERVER_PORT .env 2>/dev/null | cut -d= -f2 || echo 9501)/api/guestbook -H "Content-Type: application/json" -d '{"author":"A","content":"Too long content '$(printf 'x' {1..2001})'"}'`
   **Pass:** HTTP 422，返回 JSON 包含 `errors.content` 验证错误

### 路由注册检查（无需启动服务器）

7. `php bin/console router:list --json 2>/dev/null | grep -o '"\/api\/guestbook"'`
   **Pass:** 输出 `"\/api\/guestbook"` 至少两次（GET 和 POST）

## Out of scope

- 留言编辑 / 删除功能
- 用户认证与关联
- 分页
- 前端页面
