---
sidebar_label: Dict 包
description: switon/dict：Dict 动作参数、DictValue 归一化与 OptionReader。
---

# Dict 包 {#dict-package}

`switon/dict` 让受控取值集合在 Switon 里可以统一为：类型化的动作参数、经校验的输入字段，以及共享的选项行。

**关键特性：**

- **类型化动作边界：** 用 `OrderStatus $status`，而不是重复裸标量检查。
- **属性归一化：** 输入对象或实体字段用 `#[DictValue(...)]` 存解析后的 item 值。
- **共享读契约：** `DictReaderInterface` 负责类型与项查找；`OptionReaderInterface` 负责选项行。
- **类型自有编码：** `Dict::typeCode()` 可显式返回，也可由类名推导。
- **默认项回退：** 缺失值可解析为类型默认项；可空参数仍可得到 `null`。

## 安装 {#installation}

**环境要求：** PHP 8.3+

```bash
composer require switon/dict
```

应用把 `Switon\Dict\DictReaderInterface` 绑到一个 reader 实现；同一数据源若还要喂 UI 选项，再绑 `Switon\Dict\OptionReaderInterface`。

本包依赖 `switon/binding` 解析动作参数，依赖 `switon/validation` 处理 `#[DictValue]` 约束。

## 快速开始 {#quick-start}

定义一个 Dict 子类：

```php
namespace App\Dict;

use Switon\Dict\Dict;

final class OrderStatus extends Dict
{
    public static function typeCode(): ?string
    {
        return 'order.status';
    }
}
```

用在动作参数上：

```php
namespace App\Controller;

use App\Dict\OrderStatus;

class OrderController
{
    public function updateAction(OrderStatus $status): void
    {
        // $status 是解析后的 dict 项，不是裸字符串。
    }
}
```

标量输入字段要归一为解析值时，在属性上使用 `#[DictValue]`：

```php
use App\Dict\OrderStatus;
use Switon\Dict\Attribute\DictValue;

final class UpdateOrderInput
{
    #[DictValue(OrderStatus::class)]
    public string $status;
}
```

若同一数据源还要驱动下拉或单选，把 `Switon\Dict\OptionReaderInterface` 绑到能从同一底层数据返回选项行的 reader。

传入值非法时，在进入动作体前就会失败：动作参数抛 `Switon\Dict\Exception\DictItemNotFoundException`（或校验器先跑时走校验错误），`#[DictValue]` 走对应约束的校验失败。

## 核心概念 {#core-concepts}

### 类型拥有的 Dict {#type-owned-dicts}

`Dict` 子类代表一组受控值。类型码可由类名定义，也可由 `typeCode()` 显式返回。

默认 `App\Areas\Order\OrderStatus` 与 `App\Dict\OrderStatus` 都会推出 `order.status`。类名推导不出可用编码时必须重写 `typeCode()`；返回空结果会抛出 `Switon\Dict\Exception\DictTypeCodeRequiredException`。

### 动作参数 {#action-parameters}

`Dict` 带有 `Switon\Binding\Attribute\ResolvedBy`，动作参数上的 `Dict` 子类经 `Switon\Dict\DictResolver` 解析。

路径很简单：

- 从 `Dict::typeCode()` 读出 dict 类型（`typeCode()` 为 `null` 时由 `Dict::class` 推导）
- 通过标量解析器按**参数名**读入参
- 参数可空且值缺失或为空时返回 `null`
- 参数非空且值缺失时，回退到 `DictReaderInterface::getDefaultItem()`
- 否则经 `DictReaderInterface::getItem()` 解析，匹配的是存储值（`DictEntry::itemValue`），不是 `itemCode`

### 字段归一化 {#field-normalization}

`#[DictValue(...)]` 打在实体或输入对象属性上。

- 用同一 dict 源校验标量输入。
- 接受后归一为存储的 item 值。
- 字段仍以标量进来，但语义由 dict 支撑。
- 与动作参数不同：**空输入不会**自动选默认项。
- 可空属性仍可归一成 `null`。

### 选项行 {#option-rows}

`OptionReaderInterface::getOptions($typeCode, $builtin = true)` 为 select、radio、tag 等返回选项行。

文档约定的行形状：`array{label: string, value: string, code: string, tag: int, default: bool}`。

- `tag` 为 `int`，reader 可用于排序、分组或组标签。
- `default` 标记在动作参数缺失时要解析的行。
- `$builtin` 参数过滤返回内置 dict 数据、应用自有数据或二者；**不属于行结构**。

## 配置 {#configuration}

在 `switon.yml` 绑定 reader：

```yaml
Switon\Dict\DictReaderInterface:
  class: App\Dict\ArrayDictReader
```

同一 reader 也服务 UI 时，把 `Switon\Dict\OptionReaderInterface` 绑到同一类。

需要查看当前选项面时：

```bash
bash bin/console config:options 'Switon\Dict\DictReaderInterface'
```

## 使用注意 {#keep-in-mind}

- 不想让类名变成最终 dict 类型码时重写 `typeCode()`。
- 空输入应保持 `null` 时，把参数或属性声明为可空。
- 只有 UI 确实需要 dict 支撑选项行时才绑 `OptionReaderInterface`。

## 下一步 {#next}

- **常见问题与边角：** [FAQ](faq.md#dict-faq)
- **设计取舍：** [设计说明](design.md#design-rationale)
