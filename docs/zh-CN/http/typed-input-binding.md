---
sidebar_label: 类型化输入绑定
description: 用来源属性与校验将请求数据绑定到类型化输入类。
---

# 类型化输入绑定 {#typed-input-binding}

用来源属性与校验，将请求数据绑定到类型化输入类。

**关键特性：**

- **来源选择** — `#[RequestBody]`、`#[RequestQuery]`、`#[RequestData]` 选定请求来源
- **根类约定** — 属性标在输入类根上，而非嵌套属性
- **绑定期校验** — 公共属性一并填充并校验
- **嵌套对象与数组** — 类型化输入可含嵌套对象与类型化数组

## 快速开始 {#quick-start}

标记一个输入类，再将其作为动作参数使用。

```php
namespace App\Input;

use Switon\Http\Attribute\RequestBody;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Validating\Attribute\Email;
use Switon\Validating\Attribute\Length;

#[RequestBody]
class CreateUserInput
{
    #[Length(4, 16)]
    public string $username;

    #[Email]
    public string $email;
}

#[RequestMapping('/user')]
class UserController
{
    #[PostMapping('')]
    public function createAction(CreateUserInput $input): array
    {
        return ['username' => $input->username, 'email' => $input->email];
    }
}
```

正文用 `#[RequestBody]`，查询用 `#[RequestQuery]`，合并请求用 `#[RequestData]`。

## 核心概念 {#core-concepts}

### 来源属性 {#source-attributes}

三种类型化输入属性选择绑定器使用的来源数组。

- `#[RequestBody]` — 已解析的正文
- `#[RequestQuery]` — 查询
- `#[RequestData]` — 合并后的请求

### 绑定模型 {#binding-model}

类型化输入绑定作用于根输入类。

- 公共属性接收请求值
- 缺失的必填属性导致校验失败
- 嵌套对象属性递归填充

### 校验 {#validation}

校验属性在绑定过程中执行。规则与消息详见 [Validation 包](../validation/README.md)。

- `Switon\Validating\Attribute\Length`
- `Switon\Validating\Attribute\Email`
- 仅在默认规则不足时使用 `Switon\Validating\Attribute\Required`

### 数组与嵌套对象 {#arrays-nested-objects}

类型化输入可包含嵌套对象与对象数组。

- 嵌套字段的错误键使用点路径，如 `user.name`
- 数组项经常规绑定路径逐项校验

## 注意 {#keep-in-mind}

- 仅根输入类需要类型化输入属性。
- `RequestBody` 与 `RequestQuery` 保持来源专一。
- 仅在合并输入才是预期契约时使用 `RequestData`。

## 下一步 {#next}

- **控制器用法：** [控制器](controller.md)
- **原始请求读取：** [请求](request.md)
- **校验细节：** [FAQ](faq.md)
