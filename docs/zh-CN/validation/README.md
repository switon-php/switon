---
sidebar_label: Validation 包
description: switon/validation：属性约束、校验时规范化与本地化错误信息。
---

# Validation 包 {#validation-package}

`switon/validation` 用属性校验字段，在同一遍中规范化取值，并渲染可本地化的错误信息。

**关键特性：**

- **一个入口** — `validate($source, $constraints)` 渐进校验字段映射（数组或对象）；未约束字段原样保留
- **属性优先规则** — 内置约束位于 `Switon\Validating\Attribute`。
- **校验过程中规范化** — `Trim`、`Defaults`、`Type`、`Email`、`Date`、`Lowercase`、`Uppercase` 等可改写值。
- **本地化消息** — 语言环境文件提供模板与标签。
- **字段内快速失败** — 某字段第一条失败规则会阻止该字段后续规则。
- **HTTP 友好错误** — `ValidateFailedException` 携带字段错误映射，通常对应状态 **400**。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+

```bash
composer require switon/validation
```

---

## 快速开始 {#quick-start}

注入 `ValidatorInterface` 并校验一份 payload。

```php
use Switon\Core\Attribute\Autowired;
use Switon\Validating\Attribute\Email;
use Switon\Validating\Attribute\Length;
use Switon\Validating\Attribute\Required;
use Switon\Validating\ValidatorInterface;

final class UserService
{
    #[Autowired] protected ValidatorInterface $validator;

    public function register(array $input): array
    {
        return $this->validator->validate($input, [
            'username' => [new Required(), new Length(4, 16)],
            'email' => [new Required(), new Email()],
        ]);
    }
}
```

若某字段失败，抛出 `ValidateFailedException`。若规则改写了值，返回的 payload 含规范化后的取值。

---

## 核心概念 {#core-concepts}

### 入口 {#entrypoints}

- `validate($source, $constraints)` 对数组或对象做渐进校验并返回同形结果（未约束字段保留；对象就地写回）；
- `beginValidate()` / `endValidate()` 供框架内手动会话。

多数应用代码从 `validate()` 开始。

### 规则 {#rules}

每个字段的规则按**声明顺序**执行。

- 第一条失败规则会**停止该字段**；
- 其它字段仍继续；
- `Defaults` 可填充缺失或空输入；
- `Type` 可转换受支持的标量与 enum 值。

### 校验会话 {#validation-session}

`Validation` 承载当前 `field`、`value`、`source` 与已收集错误。自定义约束需要共享状态或直接添加错误时使用。

### 内置约束 {#built-in-constraints}

常见分组：

- 存在性：`Required`、`NotEmpty`、`Defaults`
- 类型与身份：`Type`、`Date`、`EnumValue`、`ConstantValue`
- 嵌套数组：`ArrayOf`
- 字符串形态：`Length`、`MinLength`、`MaxLength`、`Alpha`、`Alnum`、`Digit`、`Xdigit`
- 格式：`Email`、`Url`、`Ip`、`Uuid`、`Mobile`、`Account`、`Regex`
- 数值与集合：`Min`、`Max`、`Range`、`Decimal`、`In`、`NotIn`
- 字符串边界：`StartsWith`、`EndsWith`
- 跨字段：`EqualTo`
- 规范化：`Trim`、`Lowercase`、`Uppercase`

### 消息标签 {#message-labels}

字段标签可来自约束、模板标签、标准 i18n 键或原始字段名。这样错误文本对人可读，又无需改动返回错误映射中的字段键。

---

## 配置 {#configuration}

仅在需要时于 `switon.yml` 覆盖模板目录与消息行为。

```yaml
# switon.yml
Switon\Validating\ValidatorInterface:
  dirs:
    - '@switon.validator.resources'
  fallback: en
  translateField: true
  translateLabel: false
```

### 选项发现 {#option-discovery}

```bash
bash bin/console config:options 'Switon\Validating\ValidatorInterface'
```

默认校验器依赖容器中可用的 `FilesystemInterface`、`LocaleInterface` 与 `TranslatorInterface`。

---

## 使用注意 {#keep-in-mind}

- `validate()` **不会**凭空发明缺失字段。
- `Defaults` 可使缺失字段出现在返回数组中。
- 顺序很重要：一条规则可能为下一条规则规范化值。
- 字段顺序遵循**约束映射**，而非源数组键顺序。
- 纯消息字符串为**字面**模板。
- 请求数据来源由 `switon/http` 选择。

---

## 下一步 {#next}

- **快速答疑：** [FAQ](faq.md#validation-faq)
- **设计取舍：** [设计说明](design.md#design-rationale)
- **HTTP 类型化入参：** [类型化输入绑定](../http/typed-input-binding.md#typed-input-binding)
- **常量约束指南：** [验证常量约束](../guides/validation-constant-constraints.md#validation-constant-constraints)
