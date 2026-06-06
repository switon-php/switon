# 类型化输入绑定 {#typed-input-binding}

Switon 将请求解析转为类型化的动作边界，使 body、query 或合并输入可作为一种已校验形态到达，而非在控制器内重复手动读取 `RequestInterface`。

**为何是亮点：**
- `#[Switon\Http\Attribute\RequestBody]`、`#[Switon\Http\Attribute\RequestQuery]` 与 `#[Switon\Http\Attribute\RequestData]` 在边界显式声明请求来源
- `Switon\Binding\InputBinder` 递归映射嵌套输入对象与 `#[Switon\Validating\Attribute\ArrayOf]` 数组，而非把形态组装留给动作代码
- `Switon\Validating\Attribute\*` 下的校验属性留在其约束的公开输入属性上
- 更少的手动 `post()`、`query()` 与 `all()` 解析意味着更少的控制器漂移与不一致的边界检查

## 本文强调的要点 {#highlights}

- 类型化输入绑定使请求边界对动作契约显式且局部。
- 用 `#[Switon\Http\Attribute\RequestBody]`、`#[Switon\Http\Attribute\RequestQuery]` 或 `#[Switon\Http\Attribute\RequestData]` 一次声明来源选择。
- 嵌套输入对象与类型化数组让较大载荷保持结构化，而无需把解析逻辑移入控制器。
- 校验规则通过 `Switon\Validating\Attribute\*` 属性属性贴近形态。

## 为何在请求边界重要 {#request-boundary}

类型化输入绑定分离控制器中常混在一起的两种关切：

- `Switon\Http\RequestInterface` 仍是原始传输边界
- 动作参数类型成为已校验的应用输入边界

该分离重要，因为动作可依赖一种已知形态，而不必在每条写入路径上从 `post()`、`query()` 或 `all()` 重复字段提取、类型转换与缺失字段检查。

## 显式来源选择而非隐式解析 {#explicit-implicit-parsing}

来源属性是契约的一部分：

- `#[Switon\Http\Attribute\RequestBody]` 仅 body 载荷
- `#[Switon\Http\Attribute\RequestQuery]` 仅 query 输入对象
- `#[Switon\Http\Attribute\RequestData]` 当合并路由、body 与 query 输入是有意为之

这是框架级改进，因为「这个字段从哪来？」不再藏在动作代码里。在读方法体之前，来源已在输入类型上可见。

## 校验与形态同在 {#validation-shape}

Switon 将请求校验贴近定义输入对象的公开属性。

这样有一处可读：

- 类型与默认规则下的必填与可选字段
- 如 `#[Switon\Validating\Attribute\Email]` 或 `#[Switon\Validating\Attribute\Length]` 的字段约束
- 嵌套对象与 `#[Switon\Validating\Attribute\ArrayOf]` 结构

结果通常比在动作中校验一个临时数组再手动复制到另一对象更简单。

## 嵌套载荷无需控制器胶水代码 {#nested-glue-code}

`Switon\Binding\InputBinder` 递归支持嵌套输入对象与类型化数组，因此较大请求体可表达为形态而非组装逻辑。

这对简单表单不那么重要，对端点更重要——否则手动解析会分散在：

- 嵌套数组存在性检查
- 各分支类型强制
- 点路径校验错误命名

框架把这些关切留在绑定层，而非在每个控制器重复。

## 实践要点 {#practical-takeaway}

当请求载荷形态是动作契约的一部分，且你不希望解析、强制与校验分散在控制器体中时，选择类型化输入绑定。

保留 `RequestInterface` 用于原始传输场景。当应用应接收一种显式、已校验形态时，使用类型化输入。

## 另见 {#see-also}

- [类型化输入绑定](../http/typed-input-binding.md) — 来源规则、嵌套绑定与归属感知输入行为
- [HTTP 包](../http/README.md) — 控制器、请求与动作边界上下文
- [Validation 包](../validation/README.md) — 共享校验模型与约束行为
