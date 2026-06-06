---
sidebar_label: YAML 包
description: switon/yaml：Switon YAML 子集、parse/interpolate 与 ${NAME} 占位符。
---

# YAML 包 {#yaml-package}

`switon/yaml` 解析 Switon YAML 子集，并可在第二轮解析 `${NAME}` 占位符。

**关键特性：**

- **严格子集** — 可预期的映射、序列、标量与块标量。
- **两步模型** — `parse()` 读取 YAML 语法，然后 `interpolate()` 解析占位符。
- **便捷 `read()`** — 在已有变量表时一步完成解析与插值。
- **清晰解析失败** — 无效子集输入抛出 `YamlParseException`。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+、`ext-json`，以及 `switon/core`。

```bash
composer require switon/yaml
```

---

## 快速开始 {#quick-start}

若已处于框架接线内部，注入 `YamlReaderInterface`。

```php
use Switon\Core\Attribute\Autowired;
use Switon\Yaml\YamlReaderInterface;

final class ConfigLoader
{
    #[Autowired] protected YamlReaderInterface $yamlReader;

    public function load(string $yaml, array $vars): array
    {
        return $this->yamlReader->read($yaml, $vars);
    }
}
```

在 bootstrap 边缘，直接使用 `new Switon\Yaml\YamlReader()` 亦可。

---

## 核心概念 {#core-concepts}

### 读取、解析、插值 {#read-parse-interpolate}

- `parse()` 将 YAML 转为 PHP 数组；
- `interpolate()` 遍历已解析字符串值并解析占位符；
- `read()` 合并以上两步。

### 子集形态 {#subset-shape}

子集支持：

- 映射；
- 序列；
- plain、单引号与双引号标量；
- 整数、浮点、布尔与 `null`；
- literal 与 folded 块标量；
- 一层 flow 序列（元素为标量）。

子集**不支持** anchor、alias、tag、merge key 或 flow mapping。

规范文法见 `packages/yaml/subset.md`。

### 占位符 {#placeholders}

占位符使用 `${NAME}` 或 `${NAME:-default}`。

- 占位符名称须以字母开头，且仅含字母、数字与下划线；
- 占位符值须为 `string`、`int`、`float` 或 `bool`；
- 除非提供 default，缺失值为错误；空字符串在有 default 时也回落到 default；
- 插值在已解析字符串上运行，即使原始 YAML 使用了引号。

### 错误 {#security-and-errors}

对无效子集输入或无效占位符语法使用 `YamlParseException`。

---

## 使用注意 {#keep-in-mind}

- 本包面向 Switon YAML **子集**，不是完整 YAML。
- 解析与插值**故意**分离。
- 纯标量根文档不在范围内。
- Tab、多文档标记、anchor、alias、tag 与 merge key 会被拒绝。
- 重复映射键会被拒绝。
- plain `null`、`~`、`true` 与 `false` **仅**小写识别。
- plain 整数与十进制浮点会转换；十六进制与科学计数法保持为字符串。
- 空输入或仅含注释的输入解析为 `[]`。

---

## 下一步 {#next}

- **快速答疑：** [FAQ](faq.md#yaml-faq)
- **设计取舍：** [设计说明](design.md#design-rationale)
- **OpenAPI YAML 工作流：** [OpenAPI 包](../openapi/README.md)
