<?php

declare(strict_types=1);

namespace Switon\Storage\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Switon\Core\Exception\FileNotFoundException;
use Switon\Core\Exception\MisuseException;
use Switon\Core\Exception\RuntimeException;
use Switon\Core\FilesystemInterface;
use Switon\Core\PathAliasInterface;
use Switon\Storage\BlobStore;
use Switon\Storage\Exception\ExtensionNotAllowedException;
use Switon\Storage\Exception\ObjectNotFoundException;
use Switon\Storage\Exception\StorageException;
use Switon\Storage\Tests\TestCase;

use function fclose;
use function file_get_contents;
use function fopen;
use function fwrite;
use function is_file;
use function is_resource;
use function is_string;
use function rewind;
use function sys_get_temp_dir;
use function tempnam;
use function uniqid;
use function unlink;

class BlobStoreTest extends TestCase
{
    #[Test]
    public function existsReturnsFilesystemExistenceForNormalizedKey(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $pathAlias = $this->createStub(PathAliasInterface::class);

        $filesystem->expects($this->once())
            ->method('exists')
            ->with('/tmp/storage/nested/file.txt')
            ->willReturn(true);

        $store = $this->makeStore($filesystem, $pathAlias);

        $this->assertTrue($store->exists("  nested\\file.txt  "));
    }

    #[Test]
    public function sizeReturnsFilesystemSizeForNormalizedKey(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $pathAlias = $this->createStub(PathAliasInterface::class);

        $filesystem->expects($this->once())
            ->method('size')
            ->with('/tmp/storage/reports/daily.txt')
            ->willReturn(123);

        $store = $this->makeStore($filesystem, $pathAlias);

        $this->assertSame(123, $store->size("  reports\\daily.txt  "));
    }

    #[Test]
    public function deleteRemovesExistingObject(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $pathAlias = $this->createStub(PathAliasInterface::class);

        $filesystem->expects($this->once())
            ->method('exists')
            ->with('/tmp/storage/object.txt')
            ->willReturn(true);

        $filesystem->expects($this->once())
            ->method('delete')
            ->with('/tmp/storage/object.txt');

        $store = $this->makeStore($filesystem, $pathAlias);

        $store->delete('object.txt');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function deleteReturnsQuietlyWhenObjectIsMissing(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $pathAlias = $this->createStub(PathAliasInterface::class);

        $filesystem->expects($this->once())
            ->method('exists')
            ->with('/tmp/storage/object.txt')
            ->willReturn(false);

        $filesystem->expects($this->never())->method('delete');

        $store = $this->makeStore($filesystem, $pathAlias);

        $store->delete('object.txt');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function putWritesContentsForNormalizedKey(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $pathAlias = $this->createStub(PathAliasInterface::class);

        $filesystem->expects($this->once())
            ->method('mkdir')
            ->with('/tmp/storage/docs');

        $filesystem->expects($this->once())
            ->method('write')
            ->with('/tmp/storage/docs/example.txt', 'payload');

        $store = $this->makeStore($filesystem, $pathAlias);

        $store->put("  docs\\example.txt  ", 'payload');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function getThrowsObjectNotFoundWhenFileMissing(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $pathAlias = $this->createStub(PathAliasInterface::class);

        $filesystem->expects($this->once())
            ->method('read')
            ->with('/tmp/storage/missing.txt')
            ->willThrowException(FileNotFoundException::of('missing'));

        $store = $this->makeStore($filesystem, $pathAlias);

        $this->expectException(ObjectNotFoundException::class);
        $store->get('missing.txt');
    }

    #[Test]
    public function getWrapsRuntimeReadFailureAsStorageException(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $pathAlias = $this->createStub(PathAliasInterface::class);

        $filesystem->expects($this->once())
            ->method('read')
            ->with('/tmp/storage/object.txt')
            ->willThrowException(RuntimeException::of('read failed'));

        $store = $this->makeStore($filesystem, $pathAlias);

        $this->expectException(StorageException::class);
        $store->get('object.txt');
    }

    #[Test]
    public function putWrapsMkdirFailureAsStorageException(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $pathAlias = $this->createStub(PathAliasInterface::class);

        $filesystem->expects($this->once())
            ->method('mkdir')
            ->with('/tmp/storage/dir')
            ->willThrowException(RuntimeException::of('mkdir failed'));

        $filesystem->expects($this->never())->method('write');

        $store = $this->makeStore($filesystem, $pathAlias);

        $this->expectException(StorageException::class);
        $store->put('dir/file.txt', 'payload');
    }

    #[Test]
    public function existsThrowsMisuseExceptionForWhitespaceOnlyKey(): void
    {
        $filesystem = $this->createStub(FilesystemInterface::class);
        $pathAlias = $this->createStub(PathAliasInterface::class);
        $store = $this->makeStore($filesystem, $pathAlias);

        $this->expectException(MisuseException::class);
        $store->exists('   ');
    }

    #[Test]
    public function writeStreamCopiesBytesToResolvedPath(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $pathAlias = $this->createMock(PathAliasInterface::class);
        $target = tempnam(sys_get_temp_dir(), 'switon-storage-write-');
        $stream = fopen('php://temp', 'rb+');

        $this->assertIsString($target);
        $this->assertTrue(is_resource($stream));
        fwrite($stream, 'stream payload');
        rewind($stream);

        $filesystem->expects($this->once())
            ->method('mkdir')
            ->with('/tmp/storage/dir');

        $pathAlias->expects($this->once())
            ->method('resolve')
            ->with('/tmp/storage/dir/file.txt')
            ->willReturn($target);

        try {
            $store = $this->makeStore($filesystem, $pathAlias);
            $store->writeStream("  dir\\file.txt  ", $stream);

            $this->assertSame('stream payload', file_get_contents($target));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_string($target) && is_file($target)) {
                unlink($target);
            }
        }
    }

    #[Test]
    public function writeStreamWrapsMkdirFailureAsStorageException(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $pathAlias = $this->createStub(PathAliasInterface::class);

        $filesystem->expects($this->once())
            ->method('mkdir')
            ->with('/tmp/storage/dir')
            ->willThrowException(RuntimeException::of('mkdir failed'));

        $stream = fopen('php://temp', 'rb+');
        $this->assertTrue(is_resource($stream));

        try {
            $store = $this->makeStore($filesystem, $pathAlias);
            $this->expectException(StorageException::class);
            $store->writeStream('dir/file.txt', $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    #[Test]
    public function readStreamThrowsStorageExceptionWhenOpenFails(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $pathAlias = $this->createMock(PathAliasInterface::class);
        $invalidPath = '/tmp/switon-storage-readstream-missing-' . uniqid('', true);

        $filesystem->expects($this->once())
            ->method('exists')
            ->with('/tmp/storage/folder')
            ->willReturn(true);

        $pathAlias->expects($this->once())
            ->method('resolve')
            ->with('/tmp/storage/folder')
            ->willReturn($invalidPath);

        $store = $this->makeStore($filesystem, $pathAlias);

        $this->expectException(StorageException::class);
        $store->readStream('folder');
    }

    #[Test]
    public function putFileThrowsExtensionNotAllowedWhenKeyExtensionNotInAllowList(): void
    {
        $filesystem = $this->createStub(FilesystemInterface::class);
        $pathAlias = $this->createStub(PathAliasInterface::class);
        $store = $this->makeStore($filesystem, $pathAlias);

        $this->expectException(ExtensionNotAllowedException::class);
        $this->expectExceptionMessage('not allowed');

        $store->putFile('report.exe', '/tmp/source.bin', 'pdf,txt');
    }

    #[Test]
    public function putFileAcceptsTrimmedAllowListCaseInsensitive(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $pathAlias = $this->createMock(PathAliasInterface::class);
        $source = tempnam(sys_get_temp_dir(), 'switon-storage-putfile-');
        $target = tempnam(sys_get_temp_dir(), 'switon-storage-putfile-out-');

        $this->assertIsString($source);
        $this->assertIsString($target);
        file_put_contents($source, 'blob');

        $filesystem->expects($this->once())
            ->method('mkdir')
            ->with('/tmp/storage/out');

        $pathAlias->expects($this->once())
            ->method('resolve')
            ->with('/tmp/storage/out/item.dat')
            ->willReturn($target);

        try {
            $store = $this->makeStore($filesystem, $pathAlias);
            $store->putFile('out/item.dat', $source, 'DAT, bin');

            $this->assertSame('blob', file_get_contents($target));
        } finally {
            if (is_string($source) && is_file($source)) {
                unlink($source);
            }
            if (is_string($target) && is_file($target)) {
                unlink($target);
            }
        }
    }

    #[Test]
    public function putFileThrowsStorageExceptionWhenLocalSourceCannotBeOpened(): void
    {
        $filesystem = $this->createStub(FilesystemInterface::class);
        $pathAlias = $this->createStub(PathAliasInterface::class);
        $store = $this->makeStore($filesystem, $pathAlias);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Failed to open local file');

        $missing = '/nonexistent/switon-storage-putfile-' . uniqid('', true) . '.txt';
        $store->putFile('doc/readme.txt', $missing, 'txt');
    }

    #[Test]
    public function putFileRejectsWhenAllowListIsEmptyString(): void
    {
        $filesystem = $this->createStub(FilesystemInterface::class);
        $pathAlias = $this->createStub(PathAliasInterface::class);
        $store = $this->makeStore($filesystem, $pathAlias);

        $this->expectException(ExtensionNotAllowedException::class);

        $store->putFile('report.txt', '/tmp/source.txt', '   ');
    }

    #[Test]
    public function writeStreamThrowsMisuseExceptionWhenArgumentIsNotStreamResource(): void
    {
        $filesystem = $this->createStub(FilesystemInterface::class);
        $pathAlias = $this->createStub(PathAliasInterface::class);
        $store = $this->makeStore($filesystem, $pathAlias);

        $this->expectException(MisuseException::class);
        $this->expectExceptionMessage('stream resource');

        $store->writeStream('key.txt', 'not-a-stream');
    }

    #[Test]
    public function existsThrowsMisuseExceptionForAbsoluteObjectKey(): void
    {
        $filesystem = $this->createStub(FilesystemInterface::class);
        $pathAlias = $this->createStub(PathAliasInterface::class);
        $store = $this->makeStore($filesystem, $pathAlias);

        $this->expectException(MisuseException::class);
        $this->expectExceptionMessage('relative');

        // Use exists(): get() wraps RuntimeException (including MisuseException) as StorageException.
        $store->exists('/absolute.txt');
    }

    #[Test]
    public function putThrowsMisuseExceptionWhenKeyContainsParentSegments(): void
    {
        $filesystem = $this->createStub(FilesystemInterface::class);
        $pathAlias = $this->createStub(PathAliasInterface::class);
        $store = $this->makeStore($filesystem, $pathAlias);

        $this->expectException(MisuseException::class);
        $this->expectExceptionMessage('..');

        $store->put('a/../../etc/passwd', 'x');
    }

    #[Test]
    public function deleteWrapsRuntimeExceptionWhenFilesystemDeleteFails(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $pathAlias = $this->createStub(PathAliasInterface::class);

        $filesystem->expects($this->once())
            ->method('exists')
            ->with('/tmp/storage/x.txt')
            ->willReturn(true);

        $filesystem->expects($this->once())
            ->method('delete')
            ->with('/tmp/storage/x.txt')
            ->willThrowException(RuntimeException::of('unlink failed'));

        $store = $this->makeStore($filesystem, $pathAlias);

        $this->expectException(StorageException::class);
        $store->delete('x.txt');
    }

    #[Test]
    public function putWrapsRuntimeWriteFailureAsStorageException(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $pathAlias = $this->createStub(PathAliasInterface::class);

        $filesystem->expects($this->once())
            ->method('mkdir')
            ->with('/tmp/storage/docs');

        $filesystem->expects($this->once())
            ->method('write')
            ->with('/tmp/storage/docs/report.txt', 'payload')
            ->willThrowException(RuntimeException::of('write failed'));

        $store = $this->makeStore($filesystem, $pathAlias);

        $this->expectException(StorageException::class);
        $store->put('docs/report.txt', 'payload');
    }

    #[Test]
    public function readStreamReturnsReadableHandleForResolvedPath(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $pathAlias = $this->createMock(PathAliasInterface::class);
        $source = tempnam(sys_get_temp_dir(), 'switon-storage-readstream-');

        $this->assertIsString($source);
        file_put_contents($source, 'stream body');

        $filesystem->expects($this->once())
            ->method('exists')
            ->with('/tmp/storage/docs/file.txt')
            ->willReturn(true);

        $pathAlias->expects($this->once())
            ->method('resolve')
            ->with('/tmp/storage/docs/file.txt')
            ->willReturn($source);

        try {
            $store = $this->makeStore($filesystem, $pathAlias);
            $handle = $store->readStream('docs/file.txt');

            $this->assertTrue(is_resource($handle));
            $this->assertSame('stream body', stream_get_contents($handle));
            fclose($handle);
        } finally {
            if (is_string($source) && is_file($source)) {
                unlink($source);
            }
        }
    }

    #[Test]
    public function readStreamThrowsObjectNotFoundWhenFileMissing(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $pathAlias = $this->createMock(PathAliasInterface::class);

        $filesystem->expects($this->once())
            ->method('exists')
            ->with('/tmp/storage/missing.bin')
            ->willReturn(false);

        $pathAlias->expects($this->never())->method('resolve');

        $store = $this->makeStore($filesystem, $pathAlias);

        $this->expectException(ObjectNotFoundException::class);
        $store->readStream('missing.bin');
    }

    protected function makeStore(
        FilesystemInterface $filesystem,
        PathAliasInterface  $pathAlias,
        string              $root = '/tmp/storage'
    ): BlobStore {
        return new class ($filesystem, $pathAlias, $root) extends BlobStore {
            public function __construct(
                protected FilesystemInterface $filesystem,
                protected PathAliasInterface  $pathAlias,
                protected string              $root
            ) {
            }
        };
    }
}
