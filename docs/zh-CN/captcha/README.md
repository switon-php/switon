---
sidebar_label: 验证码包
description: switon/captcha：无 Session 挑战、共享 Store、CaptchaRequired 与 verify。
---

# 验证码包 {#captcha-package}

`switon/captcha` 生成图形验证码挑战，并把答案校验到共享存储里，任意 Worker 都能校验其它节点签发的挑战。适合 HTTP API、SPA 和传统表单里的一套统一流程，不依赖粘性 PHP Session。

**关键特性：**

- **无 Session 的客户端流：** `create()` 返回 `key` 与 `blob`；受保护请求里随表提交 `captcha_key` 与 `captcha_code`。
- **动作守卫：** `Switon\Captcha\Attribute\CaptchaRequired` 在控制器方法体执行前完成校验。
- **共享存储校验：** 答案在 `Switon\Captcha\StoreInterface`（默认 Redis），不在 PHP Session 里。
- **渲染自动选型：** 有 Imagick 用 Imagick，否则退回 GD；两者皆无则硬失败。
- **类型化失败：** 校验错误映射到 `Switon\Captcha\Exception\InvalidCaptchaException` 子类；缺输入在绑定 `CaptchaProof` 时映射到 `CaptchaProofRequiredException`。

## 安装 {#installation}

**环境要求：** PHP 8.3+、共享后端（默认 Redis），以及 `ext-imagick` 或 `ext-gd`。

```bash
composer require switon/captcha
```

## 快速开始 {#quick-start}

对外暴露 `create()`，在受保护动作上加 `#[CaptchaRequired]`。

```php
namespace App\Controller;

use Switon\Captcha\Attribute\CaptchaRequired;
use Switon\Captcha\CaptchaInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('')]
class ContactController
{
    #[Autowired] protected CaptchaInterface $captcha;

    #[GetMapping('/captcha')]
    public function captchaAction(): array
    {
        return $this->captcha->create();
    }

    #[PostMapping('/contact')]
    #[CaptchaRequired]
    public function submitAction(): array
    {
        return ['success' => true];
    }
}
```

`blob` 已是 `data:image/jpeg;base64,...` URI，直接赋给 `<img src>`，`key` 作为 `captcha_key` 回传：

```js
const payload = await fetch('/captcha').then((r) => r.json());
document.querySelector('#captcha-image').src = payload.blob;
formData.set('captcha_key', payload.key);
```

## 核心概念 {#core-concepts}

流程是：`create()` → 客户端渲染 blob → 客户端随请求提交证明 → 守卫或解析器执行 `verify()`。

### 挑战证明 {#challenge-proof}

`CaptchaInterface::create()` 返回 `['key' => '<uuid>', 'blob' => 'data:image/jpeg;base64,...']`。客户端保留 `key`，展示 `blob`，并在受保护请求中提交 `captcha_key` 与 `captcha_code`。

校验有两种入口：

- **`#[CaptchaRequired]`** —— 加在动作方法上的守卫属性。从 POST 读取 `captcha_key`、`captcha_code`（字段名可在属性上配置），在方法体运行前调用 `verify()`。缺输入会变成 `CaptchaNotFoundException`，因为空值仍会进入存储查询路径。
- **`CaptchaProof` 参数** —— 在方法签名里绑定 `Switon\Captcha\CaptchaProof`。解析器在 `captcha_key` 或 `captcha_code` 缺失、为空时直接抛出 `CaptchaProofRequiredException`，再由你的代码显式 `verify()`。

```php
use Switon\Captcha\CaptchaProof;

$this->captcha->verify(CaptchaProof::of($key, $code));
```

### 共享存储 {#shared-store}

浏览器只拿到 `key` 与图片。预期答案、`created_at`、`ttl` 与剩余 `attempts` 在 `StoreInterface` 里。共库的任意 Worker都能校验在另一台机器上创建的挑战。默认实现基于 Redis；换实现即绑定不同的 `StoreInterface`。

### 尝试次数语义 {#attempt-semantics}

**不匹配与匹配成功都会消耗一次 attempt**，暴力猜解与猜中成本一致。`not_found`、`data_invalid`、`too_frequent`、`expired`、`attempts_exhausted` 等预检会先短路，不改变计数。被拒后应刷新挑战；在同一 key 上反复输错最终会触发 `CaptchaAttemptsExhaustedException`。

## 配置 {#configuration}

原型阶段默认即可。要先收紧流程，优先调 `min_interval` 和 `attempts`；只有换后端或前缀时才动 `StoreInterface`。

```yaml
# switon.yml
Switon\Captcha\CaptchaInterface:
  min_interval: 1.0
  attempts: 2

Switon\Captcha\StoreInterface:
  prefix: 'captcha:prod:'
```

### 选项发现 {#option-discovery}

```bash
bash bin/console config:options 'Switon\Captcha\CaptchaInterface'
bash bin/console config:options 'Switon\Captcha\StoreInterface'
```

## 使用注意 {#keep-in-mind}

- 浏览器永远不持有答案；每个 Worker 校验都要能访问共享存储。
- 面向用户的常规失败捕获 `InvalidCaptchaException`；仅在绑定 `CaptchaProof`、需要区分「缺字段」与「码错」时再单独处理 `CaptchaProofRequiredException`。
- `distort` 仅 Imagick 生效，GD 会忽略。各机扩展不一致时，混用渲染相关选项要小心。
- 验证码比对**不区分大小写**。

## 下一步 {#next}

- **排错与默认行为：** [FAQ](faq.md#captcha-faq)
- **取舍与设计：** [设计说明](design.md#design-rationale)
- **相关：** [Validation](../validation/README.md)、[Throttle](../throttle/README.md)、[Redis](../redis/README.md)
