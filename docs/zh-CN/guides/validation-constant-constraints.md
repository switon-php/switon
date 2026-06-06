# 验证常量约束 {#validation-constant-constraints}

当你需要借助 `Switon\Validating\Attribute\ConstantValue` 做基于类常量的验证时，使用本指南。

## 用法模式 {#usage-patterns}

对基于类常量的验证使用 `Switon\Validating\Attribute\ConstantValue`。
下文中的应用输入类与常量类名仅为示例。

```php
use Switon\Validating\Attribute\ConstantValue;

final class CreateTicketInput
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    #[ConstantValue]
    public string $status;
}
```

默认情况下，`ConstantValue` 以被校验字段名为常量前缀：

- 对 `$status`，会检查名为 `STATUS_*` 的常量
- 除非覆盖 `class`，否则常量定义在当前输入对象上

若常量定义在另一类上，传入 `class`：

```php
use App\Domain\TicketStatus;
use Switon\Validating\Attribute\ConstantValue;

final class CreateTicketInput
{
    #[ConstantValue(class: TicketStatus::class)]
    public string $status;
}
```

若字段名与常量前缀不一致，传入 `prefix`：

```php
use Switon\Validating\Attribute\ConstantValue;

final class CreateTicketInput
{
    public const TYPE_BUG = 'bug';
    public const TYPE_TASK = 'task';

    #[ConstantValue(prefix: 'type')]
    public string $kind;
}
```

## 下一步 {#next}

- **在 enum 与常量验证之间选择：** [Validation 包](../validation/README.md#built-in-constraints)
- **校验 HTTP 输入类：** [类型化输入绑定](../http/typed-input-binding.md)
