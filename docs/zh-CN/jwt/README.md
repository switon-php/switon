---
sidebar_label: JWT 包
description: switon/jwt：应用自有 HMAC JWT、decode 信任边界与 kid 族路由。
---

# JWT 包 {#jwt-package}

`switon/jwt` 通过**单一信任边界**签发并校验 **HMAC JWT**。适用于应用自有令牌边界：同一信任域共用一套算法与密钥策略。公钥联邦、刷新令牌策略与更广认证流程在包外处理。

**关键特性：**

- **单一信任边界：** `decode()` 在 claims 进入业务逻辑前校验结构、头字段、签名与时间声明。
- **HMAC 签名：** 支持 `HS256`、`HS384`、`HS512`。
- **令牌族路由：** 可选头 `kid` 映射到派生键 `jwt:{kid}`，隔离不同令牌族。
- **DI 友好默认：** 常规 Switon 应用中 `Switon\Jwt\JwtInterface` 解析为 `Switon\Jwt\Jwt`。
- **类型化失败：** 畸形、过期、未生效、签名、算法与 `kid` 不匹配等情况分别失败。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+、`switon/core`、`switon/crypto`

```bash
composer require switon/jwt
```

---

## 快速开始 {#quick-start}

注入 `Switon\Jwt\JwtInterface`，用 `encode()` 签发，用 `decode()` 校验。

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Jwt\JwtInterface;

class AuthService
{
    #[Autowired] protected JwtInterface $jwt;

    public function issueForUser(int $userId): string
    {
        return $this->jwt->encode(['user_id' => $userId], 3600);
    }

    /** @return array<string, mixed> */
    public function readToken(string $token): array
    {
        return $this->jwt->decode($token);
    }
}
```

`encode()` 始终设置 `iat`：`ttl > 0` 时写入 `exp`，`ttl <= 0` 时移除 `exp`。`decode()` 仅在**全部**校验通过后返回 claims。

不同令牌族需要不同密钥时使用 `kid`：

```php
$token  = $this->jwt->encode(['user_id' => 123], 3600, 'tenant:acme:v1');
$claims = $this->jwt->decode($token, 'tenant:acme:v1');
```

---

## 核心概念 {#core-concepts}

### 信任边界 {#trust-boundary}

`decode()` 即唯一信任边界：校验令牌格式、头 `alg`、可选头 `typ`、可选头 `kid` 类型、payload 结构、当调用方与令牌均携带 `kid` 时二者是否一致、签名，以及 `exp` / `nbf`。**全部**通过后 claims 才被视为可信输入。

### 时间声明 {#time-claims}

`encode()` 自动写入 `iat`，并在 `ttl > 0` 时写入 `exp`。`decode()` 拒绝非数值的 `exp` 与 `nbf`。`leeway`（秒）在时钟漂移时放宽对 `exp` / `nbf` 的检查。

### 令牌族路由 {#token-family-routing}

无 `kid` 时，JWT 使用配置的 `key`，或在 `key` 为 null 时从 `jwt` 派生默认密钥。

有 `kid` 时改为派生 `jwt:{kid}`。`decode()` 上有两点值得关注：

- 调用方传入期望 `kid` 且令牌头中的 `kid` 不同时，`decode()` 在验签前抛出 `KidMismatchException`
- 调用方传入 `null` 而令牌头仍带 `kid` 时，由该头驱动密钥解析，并对 `jwt:{kid}` 验签

### 失败类型 {#failures}

| 异常 | 触发条件 |
|---|---|
| `NoCredentialException` | 空 token 字符串 |
| `MalformedException` | 结构、头或 claims 形状损坏 |
| `AlgorithmMismatchException` | 头 `alg` 与配置算法不一致 |
| `KidMismatchException` | 调用方 `kid` 与令牌头 `kid` 不一致 |
| `SignatureException` | 签名验证失败 |
| `ExpiredException` | 当前时间晚于 `exp`（考虑 `leeway`） |
| `NotBeforeException` | 当前时间早于 `nbf`（考虑 `leeway`） |

---

## 配置 {#configuration}

```yaml
# switon.yml
Switon\Jwt\JwtInterface:
  alg: HS256
  key: null
  leeway: 0
```

`key` 使用**小写十六进制、偶数位**；`key: null` 时经 `Switon\Crypto\CipherInterface` 派生默认密钥。配置的十六进制长度**不必**与算法强度严格一致。

---

## 选项发现 {#option-discovery}

```bash
bash bin/console config:options 'Switon\Jwt\JwtInterface'
```

---

## 使用注意 {#keep-in-mind}

- `decode()` **不是**「只验签」路径；应将其返回值视为 claims 的**唯一**可信来源。
- `kid` 用于令牌族，**不是** payload 业务数据。
- **先**校验 JWT，**再**对 claims 应用授权或业务规则。
- `jwt_encode()` / `jwt_decode()` 适合轻量代码，服务应注入 `JwtInterface`；辅助函数依赖已启动容器，经 `App::get()` 解析 `JwtInterface`。

---

## 下一步 {#next}

- **短答：** [FAQ](faq.md#jwt-faq)
- **边界选型：** [设计说明](design.md#design-rationale)
- **对已通过校验的 claims 做权限约束：** [授权包](../authz/README.md)
