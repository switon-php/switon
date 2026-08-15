<?php

declare(strict_types=1);

namespace Switon\Llm\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Part\MediaPart;
use Switon\Core\Exception\FileNotFoundException;
use Switon\Core\FilesystemInterface;
use Switon\Core\PathAliasInterface;
use Switon\Llm\RefReader;
use Switon\Llm\RefReaderInterface;
use Switon\Llm\Tests\TestCase;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function rmdir;
use function str_repeat;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(RefReader::class)]
class RefReaderTest extends TestCase
{
    public function testResolveRemoteReturnsSameAsRef(): void
    {
        $reader = $this->makeReader();
        $url = 'https://cdn.example.com/a.png';

        $this->assertSame($url, $reader->resolve(MediaPart::of($url, 'image/png')));
    }

    public function testResolveMmFileReturnsSameAsRef(): void
    {
        $reader = $this->makeReader();
        $ref = 'mm_file://file_abc';

        $this->assertSame($ref, $reader->resolve(MediaPart::of($ref, 'video/mp4')));
    }

    public function testResolveAcceptsCaseInsensitiveSchemes(): void
    {
        $reader = $this->makeReader();

        $https = 'HTTPS://cdn.example.com/a.png';
        $this->assertSame($https, $reader->resolve(MediaPart::of($https, 'image/png')));

        $mm = 'MM_FILE://file_abc';
        $this->assertSame($mm, $reader->resolve(MediaPart::of($mm, 'video/mp4')));
    }

    public function testResolveRejectsDisallowedUrlScheme(): void
    {
        $reader = $this->makeReader();

        $this->assertNull($reader->resolve(MediaPart::of('file:///tmp/a.png', 'image/png')));
        $this->assertNull($reader->resolve(MediaPart::of('FILE:///tmp/a.png', 'image/png')));
    }

    public function testResolveReadsAbsolutePathUnderTempDir(): void
    {
        $file = sys_get_temp_dir() . '/switon-refreader-tmp-' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($file, 'TMPPNG');

        try {
            $this->assertSame(
                'TMPPNG',
                $this->makeReader()->resolve(MediaPart::of($file, 'image/png'), '@threads/tid'),
            );
        } finally {
            @unlink($file);
        }
    }

    public function testResolveRejectsAbsolutePathOutsideTempRoots(): void
    {
        $root = sys_get_temp_dir() . '/switon-refreader-outside-' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        $file = $root . '/secret.png';
        file_put_contents($file, 'SECRET');

        try {
            // No jail: absolute refs read directly, matching the `media` tool's reach.
            $this->assertSame(
                'SECRET',
                $this->makeReader()->resolve(MediaPart::of($file, 'image/png'), '@threads/tid'),
            );
        } finally {
            @unlink($file);
            @rmdir($root);
        }
    }

    public function testResolveReadsRelativePathUnderDatadir(): void
    {
        $root = sys_get_temp_dir() . '/switon-refreader-' . bin2hex(random_bytes(4));
        mkdir($root . '/tid', 0777, true);
        file_put_contents($root . '/tid/shot.png', 'PNG');

        try {
            $this->container->get(PathAliasInterface::class)->set('@threads', $root);
            $reader = $this->container->get(RefReaderInterface::class);

            $part = MediaPart::of('shot.png', 'image/png');
            $resolved = $reader->resolve($part, '@threads/tid');

            $this->assertSame('PNG', $resolved);
            $this->assertNotSame($part->ref, $resolved);
        } finally {
            @unlink($root . '/tid/shot.png');
            @rmdir($root . '/tid');
            @rmdir($root);
        }
    }

    public function testResolveRejectsDotDotEscape(): void
    {
        $root = sys_get_temp_dir() . '/switon-refreader-' . bin2hex(random_bytes(4));
        mkdir($root . '/tid', 0777, true);
        file_put_contents($root . '/secret.png', 'SECRET');

        try {
            $this->container->get(PathAliasInterface::class)->set('@threads', $root);
            $reader = $this->container->get(RefReaderInterface::class);

            $this->assertNull($reader->resolve(MediaPart::of('../secret.png', 'image/png'), '@threads/tid'));
        } finally {
            @unlink($root . '/secret.png');
            @rmdir($root . '/tid');
            @rmdir($root);
        }
    }

    public function testResolveRejectsOverMaxBytes(): void
    {
        $root = sys_get_temp_dir() . '/switon-refreader-' . bin2hex(random_bytes(4));
        mkdir($root . '/tid', 0777, true);
        file_put_contents($root . '/tid/big.bin', str_repeat('x', 64));

        try {
            $this->container->get(PathAliasInterface::class)->set('@threads', $root);
            $reader = $this->make(RefReader::class, ['maxBytes' => 32]);

            $this->assertNull($reader->resolve(MediaPart::of('big.bin', 'image/png'), '@threads/tid'));
        } finally {
            @unlink($root . '/tid/big.bin');
            @rmdir($root . '/tid');
            @rmdir($root);
        }
    }

    public function testResolveRelativeWithoutDatadirReturnsNull(): void
    {
        $this->assertNull($this->makeReader()->resolve(MediaPart::of('shot.png', 'image/png')));
    }

    public function testResolveMissingRelativeFileReturnsNull(): void
    {
        $root = sys_get_temp_dir() . '/switon-refreader-' . bin2hex(random_bytes(4));
        mkdir($root . '/tid', 0777, true);

        try {
            $this->container->get(PathAliasInterface::class)->set('@threads', $root);
            $reader = $this->container->get(RefReaderInterface::class);

            $this->assertNull($reader->resolve(MediaPart::of('missing.png', 'image/png'), '@threads/tid'));
        } finally {
            @rmdir($root . '/tid');
            @rmdir($root);
        }
    }

    public function testResolveReadsAnyAbsolutePath(): void
    {
        $file = sys_get_temp_dir() . '/switon-refreader-tmp-' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($file, 'TMPPNG');

        try {
            $this->assertSame('TMPPNG', $this->makeReader()->resolve(MediaPart::of($file, 'image/png')));
        } finally {
            @unlink($file);
        }
    }

    public function testResolveEmptyAbsoluteFileReturnsNull(): void
    {
        $file = sys_get_temp_dir() . '/switon-refreader-empty-' . bin2hex(random_bytes(4)) . '.bin';
        file_put_contents($file, '');

        try {
            $this->assertNull($this->makeReader()->resolve(MediaPart::of($file, 'image/png')));
        } finally {
            @unlink($file);
        }
    }

    public function testResolveReturnsNullWhenFileVanishesBeforeRead(): void
    {
        $file = sys_get_temp_dir() . '/switon-refreader-vanish-' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($file, 'PNG');

        // Reports a positive size but cannot read — models a file deleted between stat and read.
        $filesystem = $this->createStub(FilesystemInterface::class);
        $filesystem->method('resolve')->willReturnCallback(static fn (string $path): string => $path);
        $filesystem->method('size')->willReturn(3);
        $filesystem->method('read')->willReturnCallback(static function () use ($file): string {
            FileNotFoundException::raise('File "{file}" vanished.', ['file' => $file]);
        });

        $reader = $this->make(RefReader::class, ['filesystem' => $filesystem]);

        try {
            $this->assertNull($reader->resolve(MediaPart::of($file, 'image/png')));
        } finally {
            @unlink($file);
        }
    }

    public function testResolveRejectsDotDotRefInsteadOfFallingBackToCwd(): void
    {
        $file = sys_get_temp_dir() . '/switon-refreader-dotdot-' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($file, 'PNG');

        // A `..` walk from any CWD that would land inside the default temp jail — the ref is
        // neither safe-relative nor absolute, so it must be rejected, not CWD-resolved.
        $ref = str_repeat('../', 30) . ltrim(sys_get_temp_dir() . '/' . basename($file), '/');

        try {
            $this->assertNull($this->makeReader()->resolve(MediaPart::of($ref, 'image/png')));
        } finally {
            @unlink($file);
        }
    }

    protected function makeReader(): RefReaderInterface
    {
        return $this->container->get(RefReaderInterface::class);
    }
}
