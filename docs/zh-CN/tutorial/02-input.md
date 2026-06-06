---
sidebar_label: 类型化输入
description: 将请求数据转为输入对象，并在业务逻辑运行前完成校验。
---

# 02 — 整洁输入 {#02-input}

通过把请求数据变成输入对象，并在业务逻辑运行前校验，整理原始输入。

## 目标 {#goal}

用类型化输入对象与校验替换对手动 `$request` 字段的读取。

**主要包：** [类型化输入绑定](../http/typed-input-binding.md#typed-input-binding) · [Validation](../validation/README.md)

## 代码 {#shape}

下文 `CreateTaskInput`、`TaskFilterInput` 等应用名仅为示例。Switon 输入属性与校验属性是稳定部分。

代码前请记住一条来源说明：

- `#[RequestBody]`、`#[RequestQuery]`、`#[RequestData]` 来自 `switon/http`
- `#[Length]`、`#[Required]`、`#[Email]` 等校验属性来自 `switon/validation`

像普通 PHP 类一样导入所用属性。若手动组装包而非完整应用，请先安装 validation 包。

```php
namespace App\Input;

use Switon\Http\Attribute\RequestBody;
use Switon\Http\Attribute\RequestQuery;
use Switon\Validating\Attribute\Length;

#[RequestBody]
class CreateTaskInput
{
    #[Length(1, 120)]
    public string $title;

    public ?string $note = null;
}

#[RequestQuery]
class TaskFilterInput
{
    public ?bool $completed = null;
}
```

```php
namespace App\Controller;

use App\Input\CreateTaskInput;
use App\Input\TaskFilterInput;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Http\Controller;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;

#[Authorize(Authorize::ANONYMOUS)]
#[RequestMapping('/tasks')]
class TaskController extends Controller
{
    #[GetMapping('')]
    public function listAction(TaskFilterInput $query): array
    {
        return ['filter' => ['completed' => $query->completed], 'tasks' => []];
    }

    #[PostMapping('')]
    public function createAction(CreateTaskInput $input): array
    {
        return [
            'task' => [
                'id' => 2,
                'title' => $input->title,
                'note' => $input->note,
                'completed' => false,
            ],
        ];
    }
}
```

action 签名现在直接展示输入形态。一条规则：HTTP 类型化输入绑定期间，无默认值且不可为 null 的 public 属性会自动视为必填。仅此已让 `public string $title;` 表现为必填；需要更友好标签或自定义消息时再加显式 `#[Required]`。

来源划分：`#[RequestBody]` 只读已解析请求体，`#[RequestQuery]` 只读查询字符串，让读写路径在视觉上分开。第 01 章的前缀规则仍适用：公开前缀如 `/api` 来自路由配置。

## 步骤 {#steps}

1. 在 create action 中用 `#[RequestBody]` 输入对象替换手工 `$request->post()`。保持输入对象小巧；一个必填字段加一个可选字段足以教授模式。
2. 仅在澄清读路径时再加 `#[RequestQuery]` 做筛选或简单读侧控制。
3. 把 `#[Length]`、`#[Email]` 等校验放在输入属性上，而非控制器里。

初学者默认：`#[RequestBody]` 用于 body，`#[RequestQuery]` 用于查询；仅当确实要把路由、POST 与 query 合并到一种形态时才用 `#[RequestData]`。

更多细节（按需）：若字段名面向传输而非人类，可在校验属性上加 `label`，例如 `#[Required(label: 'Task title')]` 配合 `public string $task_title;`。首轮不必考虑嵌套对象、类型化数组或自定义校验消息；也尚不必考虑服务装配或持久化。

## 验证 {#verify}

仍用第 `01` 章的 `/tasks` 路由。控制器应接收类型化对象，而不再直接读原始请求。

在 `http://127.0.0.1:9501/api/tasks` 上各试一次合法与非法请求：

```bash
curl -X POST http://127.0.0.1:9501/api/tasks \
  -H 'Content-Type: application/json' \
  -d '{"title":"Write docs","note":"Keep it short"}'

curl -i -X POST http://127.0.0.1:9501/api/tasks \
  -H 'Content-Type: application/json' \
  -d '{"title":""}'
```

合法请求应以类型化输入进入控制器；非法请求应返回 HTTP 错误，而不是正常的 `task` 载荷，且失败应发生在业务逻辑之前。

## 下一步 {#next}

类型化输入可信之后，打开 [03 — 服务边界](03-service.md#03-service)，把业务规则移出控制器。
