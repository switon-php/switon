---
sidebar_label: Crypto 包
description: switon/crypto：CipherInterface、derived/raw/auto、明文校验与 RotatingCipher。
---

# Crypto 包 {#crypto-package}

`switon/crypto` 统一可逆对称加密、确定性派生密钥、结构化明文校验与密钥轮换。适合希望应用只依赖稳定的 `CipherInterface`，而不是到处直接调 `openssl_*` 的场景。

**关键特性：**

- **单一加密契约：** `Switon\Crypto\CipherInterface` 把可逆加密收敛到一条边界上。
- **密钥模式：** `mode` 决定每次调用传入的密钥材料是由配置的 `secret` 派生，还是当作原始小写十六进制字节使用。
- **明文校验：** `Switon\Crypto\ValidatorInterface` 在 `decrypt()` 返回前用内置规则或可调用对象检查解密结果。
- **轮换：** `Switon\Crypto\RotatingCipher` 在读侧保留旧密文可读性，写入只用当前 cipher。
- **确定性派生：** `getDerivedKey()` 为其它组件返回可重复的小写 hex 密钥材料。
- **密钥生成命令：** `bash bin/console key:generate` 为新部署打印密钥串。

## 安装 {#installation}

**环境要求：** PHP 8.3+、`ext-openssl`、`ext-mbstring`

```bash
composer require switon/crypto
```

## 快速开始 {#quick-start}

在 `derived` 模式下配置一个 `secret`，注入 `CipherInterface`，并在明文有固定形状时传入校验规则。

```yaml
# switon.yml
Switon\Crypto\CipherInterface:
  secret: '<master-secret>'
  mode: derived
```

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Crypto\CipherInterface;

final class UserSecretService
{
    #[Autowired] protected CipherInterface $cipher;

    public function encryptProfile(int $userId, array $profile): string
    {
        return $this->cipher->encrypt(
            json_encode($profile, JSON_THROW_ON_ERROR),
            "user:{$userId}"
        );
    }

    public function decryptProfile(int $userId, string $payload): array
    {
        $json = $this->cipher->decrypt($payload, "user:{$userId}", 'json');

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }
}
```

密文载荷是二进制（`[IV][密文]`，AEAD 方法下为 `[IV][密文][tag]`）。只有当存储或传输无法承载裸字节时再 Base64。

迁移窗口内要轮换密钥时，在接口后绑定 `RotatingCipher`，并分别配置 `newCipher` 与 `legacyCipher`：

```yaml
# switon.yml
Switon\Crypto\CipherInterface:
  class: Switon\Crypto\RotatingCipher
  newCipher:
    class: Switon\Crypto\Cipher
    secret: '<current-secret>'
    mode: derived
  legacyCipher:
    class: Switon\Crypto\Cipher
    secret: '<legacy-secret>'
    mode: raw
```

## 核心概念 {#core-concepts}

### 密钥模式 {#key-modes}

`Switon\Crypto\Cipher` 按所配置的 `mode` 解释每次调用传入的 `$key` 参数：

- `derived` —— 始终用 `secret` 与调用方提供的密钥材料派生二进制密钥。新项目推荐。
- `raw` —— 将 `$key` 视为真实的小写十六进制密钥（长度 ≥ 32 且为 16 的倍数），不做派生。
- `auto` —— 输入符合小写 hex 形态时走 `raw`，否则退回派生。

`derived` 模式要求非空 `secret`，否则会抛出 `Switon\Crypto\Exception\MissingSecretException`。

### 明文校验 {#plaintext-validation}

`decrypt()` 可在返回解码文本前对其执行可选规则。内置规则：`ascii`、`utf8`、`json`、`numeric`、`base64`、`uuid`、`email`。任意内置规则前加 `?` 可允许空串（例如 `?json`）。也可传入可调用 `fn(string $value): bool`。`null` 跳过校验，但 **`RotatingCipher` 不允许**。

### 密钥轮换 {#key-rotation}

`Switon\Crypto\RotatingCipher` 在迁移窗口内组合两个 cipher。

- 新写入只经新 cipher。
- `decrypt()` 要求校验规则非空，并先尝试新 cipher。
- 新路径抛出任意 `Switon\Crypto\Exception` 时会回退到 legacy cipher；非 crypto 异常仍上抛。
- Legacy 回退调用 `legacyCipher->decrypt($text, $key)`，**不再**套用同一规则，因为旧格式可能早于当前形状。
- `getDerivedKey()` 始终用新 cipher。

### 确定性派生密钥 {#derived-keys}

`getDerivedKey(string $type, int $bits)` 返回小写 hex 密钥材料。同一 `secret` 下，相同 `$type` 与 `$bits` 结果相同，便于 JWT 签名密钥等需要稳定查找的场景。`$bits` 须为正且为 8 的倍数。

### 密钥生成 {#key-generation}

`bash bin/console key:generate` 打印随机字符串，用作 `secret`。默认相当于 `RandomInterface::chars(32)`。用 `--length=<n>` 改长度，`--lowercase` 输出小写。

## 配置 {#configuration}

```yaml
# switon.yml
Switon\Crypto\CipherInterface:
  secret: '<master-secret>'
  mode: derived
```

只有要对齐已有数据存储或特定 OpenSSL 配置时才动 `method`、`algo`。默认值不在本文固化；用选项发现查询当前安装版本。

### 选项发现 {#option-discovery}

```bash
bash bin/console config:options 'Switon\Crypto\CipherInterface'
```

## 使用注意 {#keep-in-mind}

- 常规数据用 `derived`；只有确实是小写 hex 密钥时用 `raw`。
- 明文有已知形状时要提供校验规则；在 `RotatingCipher` 上规则必选。
- 密文是二进制，仅在传输或存储需要时再编码。
- 旧密文全部迁移完毕后去掉 `legacyCipher`，避免轮换层掩盖新问题。

## 下一步 {#next}

- **错误、规则与轮换：** [FAQ](faq.md#crypto-faq)
- **取舍与设计：** [设计说明](design.md#design-rationale)
