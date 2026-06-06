---
sidebar_label: HTTP 基础
description: 用路由、控制器与 JSON 响应构建首批 Switon HTTP 端点。
---

# 01 — HTTP 端点 {#01-http}

先从请求路径入手：路由、控制器、JSON 响应，以及一个刻意精简的任务示例。

## 目标 {#goal}

建立一条最简 HTTP 路径：路由、控制器与 JSON 响应。

**主要包：** [HTTP](../http/README.md#quick-start) · [Router](../router/README.md#quick-start)

**本章还会用到：** [Authorization](../authz/README.md) 仅用于一处显式公开访问标记。本章不必先学授权包；该标记可避免启用 authz 的骨架应用拦截教程首条路由。

## 代码 {#shape}

下文 `TaskController` 等应用名仅为示例。Switon 路由属性、控制器基类与请求接口是稳定部分。
包含 `Switon\Authorizing\Attribute\Authorize` 导入，是因为已发布骨架可能在教程单独讲 authz 之前就启用了授权默认项。

```php
namespace App\Controller;

use Switon\Authorizing\Attribute\Authorize;
use Switon\Http\Controller;
use Switon\Http\RequestInterface;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;

#[Authorize(Authorize::ANONYMOUS)]
#[RequestMapping('/tasks')]
class TaskController extends Controller
{
    #[GetMapping('')]
    public function listAction(): array
    {
        return [
            'tasks' => [
                ['id' => 1, 'title' => 'Read the tutorial', 'completed' => false],
            ],
        ];
    }

    #[PostMapping('')]
    public function createAction(RequestInterface $request): array
    {
        return [
            'task' => [
                'id' => 2,
                'title' => (string) $request->post('title', ''),
                'completed' => false,
            ],
        ];
    }
}
```

代码刻意保持简短，用于教授首条路由形态：

- `#[Authorize(Authorize::ANONYMOUS)]` 在已发布骨架等启用 authz 的环境中保持本路由公开
- 类级 `#[RequestMapping]` 定义控制器前缀
- `#[GetMapping]`、`#[PostMapping]` 等动词映射定义 action
- 返回数组足以建立首条 JSON 形态的成功响应

继续扩展前请记住：`POST` action 仍故意粗糙。第 02 章会用类型化输入绑定替代对 `RequestInterface` 的原始读取。若应用已有 authz 默认项或全局路由前缀，请以 `router:list` 与 `router:prefix` 的真实输出为准。

## 步骤 {#steps}

1. 从 `#[RequestMapping('/tasks')]` 与 `#[GetMapping('')]` 开始。一条读路由足以验证模式，再扩展不迟。
2. 添加 `#[PostMapping('')]`，从 `RequestInterface` 读取一个字段。此阶段原始输入可接受，目标只是看见一条写形路由成功；勿把此模式固化为长期方案。
3. 两个 action 都返回数组，让路由已经产生 JSON 形态的成功响应。

本章理解到此即可：控制器类 + 路由属性 → 路由存在；`RequestInterface` → 需要时访问原始请求；数组返回 → 常规 JSON 成功响应。暂不要迷你仓储、基类控制器或缓存。

## 验证 {#verify}

若应用尚未运行，先启动：

```bash
SERVER_TYPE=php php public/index.php
```

在深入调试前先检查路由发现：

```bash
bash bin/console router:list
bash bin/console router:prefix
```

- `router:list` 显示已注册内容
- `router:prefix` 显示应用是否前置全局公开前缀（如 `/api`）

若路由缺失，检查控制器发现路径，并确认类上有类级 `#[RequestMapping]`。

也可直接请求 `http://127.0.0.1:9501`。在验证应用与已发布骨架中，通常对应 `/api/tasks`：

```bash
curl http://127.0.0.1:9501/api/tasks
curl -X POST http://127.0.0.1:9501/api/tasks \
  -H 'Content-Type: application/json' \
  -d '{"title":"Read the tutorial"}'
```

## 下一步 {#next}

HTTP 路径稳定后，打开 [02 — 整洁输入](02-input.md#02-input)。
