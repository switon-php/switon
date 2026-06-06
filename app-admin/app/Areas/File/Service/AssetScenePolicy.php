<?php

declare(strict_types=1);

namespace App\Areas\File\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Core\Exception\RuntimeException;
use Switon\Core\TranslatorInterface;

use function array_unique;
use function implode;
use function in_array;
use function preg_replace;
use function str_starts_with;
use function strtolower;
use function trim;

class AssetScenePolicy
{
    protected const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
    protected const DEFAULT_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'zip', 'txt', 'md', 'csv', 'json',
    ];

    /**
     * @var array<string, array{extensions?: list<string>, mimePrefixes?: list<string>, mimeTypes?: list<string>, message?: string}>
     */
    #[Autowired] protected array $scenes = [
        'avatar' => [
            'extensions' => self::IMAGE_EXTENSIONS,
            'mimePrefixes' => ['image/'],
            'message' => 'file.scene_avatar_images_only',
        ],
        'notice_cover' => [
            'extensions' => self::IMAGE_EXTENSIONS,
            'mimePrefixes' => ['image/'],
            'message' => 'file.scene_notice_cover_images_only',
        ],
        'notice_attachment' => [
            'extensions' => self::DEFAULT_EXTENSIONS,
        ],
        '*' => [
            'extensions' => self::DEFAULT_EXTENSIONS,
        ],
    ];

    #[Autowired] protected int $maxUploadSizeMb = 10;

    #[Autowired] protected TranslatorInterface $translator;

    /**
     * @return array{extensions: list<string>, mimePrefixes: list<string>, mimeTypes: list<string>, message: string}
     */
    public function get(string $scene): array
    {
        $policy = $this->scenes[$scene] ?? $this->scenes['*'] ?? [];
        $extensions = $policy['extensions'] ?? [];
        if ($extensions === []) {
            RuntimeException::raise('Upload scene "{scene}" must declare allowed extensions.', ['scene' => $scene]);
        }

        return [
            'extensions' => $extensions,
            'mimePrefixes' => $policy['mimePrefixes'] ?? [],
            'mimeTypes' => $policy['mimeTypes'] ?? [],
            'message' => (string)($policy['message'] ?? ''),
        ];
    }

    public function allowedExtensions(string $scene): string
    {
        return implode(',', $this->get($scene)['extensions']);
    }

    /**
     * @return array<string, string>
     */
    public function acceptMap(): array
    {
        $accepts = [];

        foreach (array_keys($this->scenes) as $scene) {
            $accepts[$scene] = $this->accept($scene);
        }

        return $accepts;
    }

    public function accept(string $scene): string
    {
        $policy = $this->get($scene);
        $accept = [];

        foreach ($policy['mimePrefixes'] as $prefix) {
            $prefix = strtolower(trim($prefix));
            if ($prefix === '') {
                continue;
            }

            if (str_ends_with($prefix, '/')) {
                $accept[] = $prefix . '*';
                continue;
            }

            $accept[] = preg_replace('/\*+$/', '', $prefix) ?: $prefix;
        }

        foreach ($policy['mimeTypes'] as $mimeType) {
            $mimeType = strtolower(trim($mimeType));
            if ($mimeType !== '') {
                $accept[] = $mimeType;
            }
        }

        foreach ($policy['extensions'] as $extension) {
            $extension = strtolower(trim($extension));
            if ($extension !== '') {
                $accept[] = '.' . ltrim($extension, '.');
            }
        }

        return implode(',', array_unique($accept));
    }

    public function validateSize(int $size): void
    {
        $maxSizeMb = $this->maxUploadSizeMb;
        $maxBytes = $maxSizeMb * 1024 * 1024;
        if ($size > $maxBytes) {
            RuntimeException::raise($this->translator->translate('file.max_size', ['size' => $maxSizeMb]));
        }
    }

    public function validate(string $scene, string $extension, string $mimeType): void
    {
        $policy = $this->get($scene);
        $message = trim($policy['message']);
        if ($message !== '' && str_contains($message, '.')) {
            $message = $this->translator->translate($message);
        }

        if ($extension === '' || !in_array($extension, $policy['extensions'], true)) {
            $allowed = implode(', ', $policy['extensions']);
            $reason = $this->translator->translate('file.scene_extension_not_allowed', ['allowed' => $allowed]);
            RuntimeException::raise($message === '' ? $reason : $message . $reason);
        }

        $mimeType = strtolower(trim($mimeType));
        if ($mimeType === '') {
            return;
        }

        if ($policy['mimeTypes'] !== [] && in_array($mimeType, $policy['mimeTypes'], true)) {
            return;
        }

        foreach ($policy['mimePrefixes'] as $prefix) {
            if ($prefix !== '' && str_starts_with($mimeType, $prefix)) {
                return;
            }
        }

        if ($policy['mimeTypes'] !== [] || $policy['mimePrefixes'] !== []) {
            $reason = $this->translator->translate('file.scene_mime_not_allowed', ['mime' => $mimeType]);
            RuntimeException::raise($message === '' ? $reason : $message . $reason);
        }
    }
}
