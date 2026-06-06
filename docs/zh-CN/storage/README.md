---
sidebar_label: Storage 包
description: switon/storage：本地 BlobStore、相对键、根目录与流式读写。
---

# Storage 包 {#storage-package}

`switon/storage` 在单一根路径下存储本地 blob。

**关键特性：**

- **本地 blob API：** `exists`、`get`、`put`、`delete`、`size`、`readStream`、`writeStream` 与 `putFile`。
- **基于根的存储：** `BlobStore` 将键保留在配置的根目录下。
- **安全键：** 键保持相对路径，不能逃逸根目录。
- **流与文件：** 可按需使用字符串、流或本地文件访问。
- **分类失败：** 缺失 blob、非法用法、存储错误与扩展名拒绝分别失败。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+

```bash
composer require switon/storage
```

---

## 快速开始 {#quick-start}

```php
use Switon\Core\Attribute\Autowired;
use Switon\Storage\BlobStoreInterface;

class AvatarService
{
    #[Autowired] protected BlobStoreInterface $blobStore;

    public function save(int $userId, string $bytes): void
    {
        $this->blobStore->put("avatars/{$userId}.bin", $bytes);
    }
}
```

---

## 核心概念 {#core-concepts}

### 键 {#keys}

- 键为相对 blob 名，例如 `avatars/42.bin`。
- 键不得以 `/` 开头。
- 键不得包含 `..`。
- 空键会被拒绝。
- 存储访问前会 trim 键，并将 `\` 规范为 `/`。

### 根目录 {#root}

- `BlobStore` 将键存于**一个**根路径下。
- 默认根为 `@runtime/storage`。
- 策略不同时，为每个根使用独立 store。

### 访问方式 {#access-modes}

- `get()` 与 `put()` 为常规整包 API。
- `readStream()` 与 `writeStream()` 适用于较大载荷；`readStream()` 解析本地路径并直接打开。
- `putFile()` 从本地文件路径复制，并按存储键上的扩展名对照允许列表校验。

### 错误 {#security-and-errors}

- `ObjectNotFoundException` — blob 缺失。
- `StorageException` — 后端失败。
- `MisuseException` — 键或流用法无效。
- `ExtensionNotAllowedException` — 文件扩展名被拒绝。

---

## 配置 {#configuration}

多数应用只需一个根。

```yaml
Switon\Storage\BlobStoreInterface:
  root: '@runtime/storage'
```

### 选项发现 {#option-discovery}

```bash
bash bin/console config:options 'Switon\Storage\BlobStoreInterface'
```

---

## 使用注意 {#keep-in-mind}

- 存储为**按键寻址**，不提供清单/列举能力。
- 元数据请放在自有数据表中。
- `readStream()` 返回的 resource **须由调用方关闭**。
- `writeStream()` **不会**关闭输入句柄，但会关闭其自行打开的输出句柄。
- `putFile()` 为便利路径，**不是**文件管理体系。

生产环境中，不要把用户持久化文件**仅**放在会随部署清理的 `@runtime` 路径；部署形态见 [将应用部署到生产环境](../guides/deploy-an-app-to-production.md#deploy-an-app-to-production)。

---

## 下一步 {#next}

- **快速答疑：** [FAQ](faq.md#storage-faq)
- **设计取舍：** [设计说明](design.md#design-rationale)
- **上传与下载：** [处理文件上传与下载](../guides/handle-file-uploads-and-downloads.md#handle-file-uploads-and-downloads)
