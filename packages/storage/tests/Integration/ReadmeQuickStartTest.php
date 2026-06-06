<?php

declare(strict_types=1);

namespace Switon\Storage\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use Switon\Core\Attribute\Autowired;
use Switon\Core\PathAliasInterface;
use Switon\Storage\BlobStoreInterface;
use Switon\Storage\Tests\TestCase;

use function file_exists;
use function file_get_contents;
use function is_dir;
use function rmdir;
use function scandir;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class ReadmeQuickStartTest extends TestCase
{
    protected string $runtimeDir;

    protected function setUpContainer(): void
    {
        $this->runtimeDir = sys_get_temp_dir() . '/switon-storage-runtime-' . uniqid('', true);
        $this->container->get(PathAliasInterface::class)->set('@runtime', $this->runtimeDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->runtimeDir);
        parent::tearDown();
    }

    #[Test]
    public function quickStartExampleWorksWithDefaultBlobStoreAutowiring(): void
    {
        $service = $this->make(ReadmeAvatarService::class);

        $this->assertFalse($service->has(42));

        $service->save(42, 'avatar-bytes');

        $this->assertTrue($service->has(42));
        $this->assertSame('avatar-bytes', $service->fetch(42));
        $this->assertSame(
            'avatar-bytes',
            file_get_contents(sprintf('%s/storage/avatars/42.bin', $this->runtimeDir))
        );
    }

    protected function removeDir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }

        $children = scandir($dir);
        if ($children === false) {
            return;
        }

        foreach ($children as $child) {
            if ($child === '.' || $child === '..') {
                continue;
            }

            $path = "$dir/$child";
            if (is_dir($path)) {
                $this->removeDir($path);
                continue;
            }

            if (file_exists($path)) {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}

class ReadmeAvatarService
{
    #[Autowired] protected BlobStoreInterface $blobs;

    public function has(int $userId): bool
    {
        return $this->blobs->exists("avatars/{$userId}.bin");
    }

    public function save(int $userId, string $bytes): void
    {
        $this->blobs->put("avatars/{$userId}.bin", $bytes);
    }

    public function fetch(int $userId): string
    {
        return $this->blobs->get("avatars/{$userId}.bin");
    }
}
