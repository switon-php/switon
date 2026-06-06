---
description: 在已有 HTTP 与存储路径上，为用户文件、安全的 blob 键与应用内下载建立首条上传/下载边界。
---

# 处理文件上传与下载 {#handle-file-uploads-and-downloads}

当一条可用的 HTTP 与存储数据路径现在需要用户自有文件、安全的 blob 键，以及由应用提供的下载，且又不把存储层做成元数据系统时，使用本指南。

## 选择首条文件边界 {#file-boundary}

从当前真正需要做决策的边界出发。

| 边界 | 最适合 | 注意 |
|---|---|---|
| HTTP 请求边缘 | 应用须接受一个上传文件、尽早拒绝坏输入，并保持控制器可读 | 用 `RequestInterface` 与 `FileInterface` 访问文件；展示名、声明类型或用户填写的描述等文本元数据校验也放在请求边界 |
| blob 存储 | 应用已知对象键，只需安全读写字节 | `BlobStoreInterface` 只存字节；**不**拥有文件名、所有者或列表行为 |
| 已存元数据 | 用户、可见性、展示名与所有权规则日后需要查询 | 在自有仓储或数据层保留 blob 键；先存元数据，再按键从 blob 存储读字节 |
| 后台处理 | 缩略图、扫描、OCR 或转换不应阻塞上传请求 | 首条上传路径保持同步；仅在延迟成为问题时再迁到 [添加持久化后台工作](add-durable-background-work.md#add-durable-background-work) |

一条实用的首条文件路径常包括：

- 一个上传端点
- 一条带 blob 键的元数据记录
- 一个经认证或应用自有的下载端点

此处还有一条路由细节：

- 若应用已应用全局路由前缀，控制器路径保持无前缀
- 若公开 URL 形态不清，在调试上传/下载代码前先运行 `bash bin/console router:prefix` 确认

## 推荐构建顺序 {#build-order}

### 1. 在一条真实的 HTTP 与一条已存数据路径之后开始 {#build-real-path}

在请求、service 与元数据边界已存在时，文件处理更容易。
不要让上传成为解释路由、类型化输入绑定或持久化混乱的第一课。

### 2. 将文件字节与文件元数据分开 {#build-separate-boundaries}

在 [Storage](../storage/README.md) 下用一条应用自有键存字节。
在自有仓储或数据模型中存所有者 id、原始文件名、媒体类型及可见性规则。

这样存储层保持精简，文件列表或所有权查询不会进入 blob 层。
首条本地路径中，`BlobStoreInterface` 通常由内置 `Switon\Storage\BlobStore` 实现支撑。常规应用配置中，在根目录 `switon.yml` 将 `Switon\Storage\BlobStoreInterface` 指向一个根目录，例如 `@runtime/uploads`。

### 3. 在 HTTP 边缘校验上传 {#build-validate-edge}

尽可能靠近请求边界拒绝缺失文件、错误扩展名、可疑媒体类型或超大载荷。
对文本元数据使用 [Validation](../validation/README.md)；对上传文件本身使用 `FileInterface` 的 `getSize()`、`getType()`、`getExtension()` 等元数据。

### 4. 生成应用自有键，再写入 blob {#build-key-write}

不要用客户端原始文件名作为存储键。
生成应用控制的键。为在文件规模扩大时避免目录性能瓶颈（inode 限制），使用按日期或按哈希分片，而非扁平目录或单用户目录。

例如 `avatars/<shard1>/<shard2>/<hash>.<ext>` 或 `attachments/<year>/<month>/<generated-id>.<ext>`。

然后通过 `BlobStoreInterface` 写入字节。较小、可放入内存的正文用 `put()`；将本地文件（如 HTTP 上传临时文件）安全流式写入存储层时用 `putFile()`。

### 5. 在 blob 边界确立后持久化元数据 {#build-persist-metadata}

blob 写入成功后，存储指向该 blob 键的元数据记录。
若 blob 写入后元数据持久化失败，立即清理该键，或通过应用自有的清理路径日后对账。

### 6. 通过一条应用路由提供下载 {#build-download-route}

下载策略留在应用内，而非公开文件系统路径。
查找元数据记录，必要时授权调用方，再通过 HTTP 下载响应返回文件。

### 7. 仅在需要时将慢后处理移出请求路径 {#build-background}

首条成功的上传路径可在元数据 + blob 存储后结束。
若扫描、缩略图、转换或归档生成开始主导延迟，将工作交给 [添加持久化后台工作](add-durable-background-work.md#add-durable-background-work)。

## 最小接线 {#minimal-wiring}

下面只用短代码展示上传请求、blob 存储、元数据记录与下载响应之间的接缝。
下文中的 `UserFileRepository`、`AvatarService`、`StoredFile` 等应用级命名仅为示例；稳定部分是 Switon 的 HTTP 与存储边界。

### 保持上传边界精简 {#wiring-upload-action}

写代码前，一条存储配置通常足够：

```yaml
# switon.yml (project root)
Switon\Storage\BlobStoreInterface:
  root: '@runtime/uploads'
```

这样首条文件路径本地且具体：上传字节进入应用自有根目录，自有仓储仍拥有元数据。

控制器应接受文件并交给一条 service 边界：

```php
namespace App\Controller;

use App\Service\AvatarServiceInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Http\RequestInterface;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('')]
class AvatarController
{
    #[Autowired] protected AvatarServiceInterface $avatars;

    #[PostMapping('/avatar')]
    public function uploadAction(RequestInterface $request): array
    {
        $stored = $this->avatars->storeAvatar(42, $request->file('avatar'));

        return ['id' => $stored->id, 'name' => $stored->original_name];
    }
}
```

校验与元数据规则靠近此边界，blob 写入本身藏在一条 service 方法之后。

### 一条 service 拥有键生成、blob 写入与元数据持久化 {#wiring-storage-service}

字节用 `BlobStoreInterface`，记录用自有仓储：

```php
use App\Repository\UserFileRepository;
use Switon\Core\Attribute\Autowired;
use Switon\Http\Request\FileInterface;
use Switon\Storage\BlobStoreInterface;

class AvatarService implements AvatarServiceInterface
{
    #[Autowired] protected BlobStoreInterface $blobs;
    #[Autowired] protected UserFileRepository $files;

    public function storeAvatar(int $userId, FileInterface $file): object
    {
        $hash = bin2hex(random_bytes(16));
        $shard = substr($hash, 0, 2) . '/' . substr($hash, 2, 2);
        $key = 'avatars/' . $shard . '/' . $hash . '.' . $file->getExtension();
        
        // putFile() bridges the local HTTP upload to the abstract storage layer,
        // and restricts allowed extensions.
        $this->blobs->putFile($key, $file->getTempName(), 'jpg,jpeg,png,webp');

        return $this->files->create([
            'user_id' => $userId,
            'blob_key' => $key,
            'original_name' => $file->getName(),
            'content_type' => $file->getType(),
            'size' => $file->getSize(),
        ]);
    }
}
```

三项职责保持清晰：

- 请求拥有传入的上传
- blob 存储在安全键下拥有字节
- 仓储拥有应用元数据

### 下载应经 HTTP 返回，而非原始路径 {#wiring-download}

元数据查找后，从一条路由提供下载：

```php
namespace App\Controller;

use App\Repository\UserFileRepository;
use Switon\Core\Attribute\Autowired;
use Switon\Http\Response\AttachmentRendererInterface;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Storage\BlobStoreInterface;

#[RequestMapping('')]
class FileDownloadController
{
    #[Autowired] protected UserFileRepository $files;
    #[Autowired] protected BlobStoreInterface $blobs;

    #[GetMapping('/files/{id}/download')]
    public function downloadAction(int $id, AttachmentRendererInterface $attachment): void
    {
        $record = $this->files->findById($id);

        $attachment->render(
            $this->blobs->get($record->blob_key),
            $record->original_name,
            $record->content_type,
        );
    }
}
```

首条路径中，用 `get()` 读取往往足够。
若载荷体积成为真正问题，再更有意识地转向基于流的读写。

## 首条路径上宜避免什么 {#pitfalls}

有意让首条上传/下载路径保持窄。

下列内容不宜过早加入：

- **用客户端原始文件名作存储键** — 键应由应用拥有且稳定
- **在 blob 层放所有者、可见性或列表规则** — 属于自有元数据存储
- **在 API 响应或控制器中暴露原始文件系统路径** — 应通过 HTTP 响应提供下载
- **在上传或下载路由中重复公开前缀** — 应用已应用路由前缀（如 `/api`）时，控制器映射保持无前缀
- **仅信任客户端上报的类型或扩展名** — 存储前在请求边缘比对文件元数据
- **在首条上传请求内做扫描、转换与预览生成** — 同步路径正确后再迁出重活
- **把首条本地 blob 路径当作远程对象存储设计** — CDN、签名 URL 与云厂商集成留到指南规模之外的方案

完成首条文件路径后，初学者应能感到：

> 我知道上传字节去哪、文件元数据在哪，以及下载如何经应用边界返回。

## 继续深入 {#go-deeper}

- **在首条请求路径之后衔接：** [构建 API](build-an-api.md#build-an-api)
- **让文件元数据落在同一已存数据模型中：** [持久化数据](persist-data.md#persist-data)
- **深入上传访问与请求文件元数据：** [Request](../http/request.md) · [HTTP](../http/README.md)
- **深入下载响应与附件：** [Response](../http/response.md)
- **在应用自有键与根目录下保留字节：** [Storage](../storage/README.md)
- **更有意识地校验输入字段与请求元数据：** [Validation](../validation/README.md)
- **将文件处理、转换或扫描移出请求路径：** [添加持久化后台工作](add-durable-background-work.md#add-durable-background-work)
- **用一小套可信测试保护文件路径：** [为已有应用添加测试](add-testing-to-a-working-app.md#add-testing-to-a-working-app)
- **为客户端文档化稳定的上传/下载契约：** [添加 API 文档](add-api-docs.md#add-api-docs)
