<?php

declare(strict_types=1);

namespace App\Areas\File\Controller;

use App\Areas\File\Entity\Asset;
use App\Areas\File\Input\AssetUploadInput;
use App\Areas\File\Repository\AssetRepository;
use App\Areas\File\Service\AssetScenePolicy;
use App\Controller\Controller;
use App\Repository\AdminRepository;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Core\Attribute\Autowired;
use Switon\Core\FilesystemInterface;
use Switon\Core\RandomInterface;
use Switon\Http\Attribute\Fields;
use Switon\Http\Attribute\Filters;
use Switon\Http\Attribute\Keyword;
use Switon\Http\Attribute\Orders;
use Switon\Http\Criteria;
use Switon\Http\Request\FileInterface;
use Switon\Orm\Page;
use Switon\Query\Paginator;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Routing\Attribute\ViewGetMapping;
use Throwable;

use function date;
use function ltrim;
use function md5;
use function md5_file;
use function str_replace;
use function strtolower;
use function substr;
use function trim;

#[Authorize]
#[RequestMapping('/file/asset')]
/**
 * Asset management controller.
 */
class AssetController extends Controller
{
    #[Autowired] protected AdminRepository $adminRepository;
    #[Autowired] protected AssetScenePolicy $assetScenePolicy;
    #[Autowired] protected AssetRepository $assetRepository;
    #[Autowired] protected FilesystemInterface $filesystem;
    #[Autowired] protected RandomInterface $random;

    #[ViewGetMapping]
    #[Fields([
        'asset_id', 'scene', 'asset_name', 'asset_ext', 'mime_type', 'asset_size', 'asset_hash', 'asset_path',
        'asset_url', 'remark', 'created_by', 'updated_by', 'created_at', 'updated_at',
    ])]
    #[Filters(['asset_id', 'scene', 'asset_ext', 'mime_type'])]
    #[Orders(['asset_id' => SORT_DESC])]
    #[Keyword(['asset_name', 'mime_type', 'asset_ext'])]
    public function indexAction(Page $page, Criteria $criteria): Paginator
    {
        return $this->assetRepository->paginate($page, $criteria->filters, $criteria->fields, $criteria->orders);
    }

    #[GetMapping]
    #[Fields(['asset_id', 'scene', 'asset_name', 'asset_url', 'asset_ext', 'mime_type'])]
    #[Orders(['asset_id' => SORT_DESC])]
    public function listAction(Criteria $criteria): array
    {
        return $this->assetRepository->all($criteria->filters, $criteria->fields, $criteria->orders);
    }

    #[PostMapping]
    public function uploadAction(AssetUploadInput $input): string|Asset
    {
        $file = $this->request->file('file');
        if (!$file instanceof FileInterface) {
            return t('file.choose_file');
        }

        $asset = null;
        try {
            $asset = $this->buildUploadAsset($file, $input);
            return $this->assetRepository->create($asset);
        } catch (Throwable $e) {
            if ($asset instanceof Asset && $asset->asset_path !== '') {
                $publicPath = '@public/' . ltrim($asset->asset_path, '/');
                if ($this->filesystem->exists($publicPath)) {
                    $this->filesystem->delete($publicPath);
                }
            }
            return $e->getMessage();
        }
    }

    #[PostMapping]
    public function editAction(Asset $asset): Asset
    {
        return $this->assetRepository->update($asset);
    }

    #[PostMapping]
    public function deleteAction(Asset $asset): string|Asset
    {
        $avatarOwner = $this->adminRepository->value(['avatar_id' => $asset->asset_id], 'admin_name');
        if (is_string($avatarOwner) && $avatarOwner !== '') {
            return t('file.asset_in_use_avatar', ['owner' => $avatarOwner]);
        }

        $publicPath = '@public/' . ltrim($asset->asset_path, '/');
        if ($this->filesystem->exists($publicPath)) {
            $this->filesystem->delete($publicPath);
        }

        return $this->assetRepository->delete($asset);
    }

    protected function buildUploadAsset(FileInterface $file, AssetUploadInput $input): Asset
    {
        $extension = strtolower($file->getExtension());
        $assetHash = md5_file($file->getTempName()) ?: md5($file->getName() . $this->random->uuid());
        $sceneValue = $input->scene;
        $uuid = strtolower(str_replace('-', '', $this->random->uuid()));
        $bucket = substr($uuid, 0, 2);
        $suffix = $extension === '' ? '' : '.' . $extension;
        $assetPath = 'uploads/file/' . $sceneValue . '/' . date('Ym') . '/' . $bucket . '/' . $uuid . $suffix;
        $mimeType = (string)$file->getType();

        $this->assetScenePolicy->validateSize($file->getSize());
        $this->assetScenePolicy->validate($sceneValue, $extension, $mimeType);

        $file->moveTo('@public/' . $assetPath, $this->assetScenePolicy->allowedExtensions($sceneValue));

        $originalName = trim((string)$file->getName());
        $assetName = $input->asset_name;
        if ($assetName === '') {
            $assetName = $originalName === '' ? 'unnamed-file' : $originalName;
        }

        return new Asset([
            'scene' => $sceneValue,
            'asset_name' => $assetName,
            'asset_ext' => $extension,
            'mime_type' => $mimeType,
            'asset_size' => $file->getSize(),
            'asset_hash' => $assetHash,
            'asset_path' => $assetPath,
            'asset_url' => '/' . ltrim($assetPath, '/'),
            'remark' => $input->remark,
        ]);
    }
}
