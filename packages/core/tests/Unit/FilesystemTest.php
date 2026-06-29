<?php

declare(strict_types=1);

namespace Switon\Core\Tests\Unit;

use Switon\Core\Exception\FileNotFoundException;
use Switon\Core\Exception\JsonException;
use Switon\Core\Exception\NotSupportedException;
use Switon\Core\Exception\RuntimeException;
use Throwable;
use Switon\Core\Filesystem;
use Switon\Core\PathAliasInterface;
use Switon\Core\Tests\TestCase;

/**
 * Filesystem behaviour (real temp dirs + {@see PathAliasInterface}); runs under unit suite for coverage.
 */
class FilesystemTest extends TestCase
{
    protected Filesystem $filesystem;
    protected PathAliasInterface $pathAlias;
    protected string $testDir;
    protected string $pharRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = $this->container->get(Filesystem::class);
        $this->pathAlias = $this->container->get(PathAliasInterface::class);

        $this->testDir = sys_get_temp_dir() . '/switon_test_' . bin2hex(random_bytes(8));
        $this->pathAlias->set('@test', $this->testDir);
        @mkdir($this->testDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testDir)) {
            $this->removeDirectory($this->testDir);
        }
        parent::tearDown();
    }

    protected function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /** @return list<string> Sorted entry names in the test dir */
    protected function entriesInTestDir(): array
    {
        $entries = array_values(array_diff(scandir($this->testDir) ?: [], ['.', '..']));
        sort($entries);

        return $entries;
    }

    public function testResolveExpandsAliasAndHomeAndLeavesRelative(): void
    {
        $this->assertSame($this->testDir . '/a.txt', $this->filesystem->resolve('@test/a.txt'));
        $this->assertSame('note.txt', $this->filesystem->resolve('note.txt'));

        $home = getenv('HOME');
        if (!is_string($home) || $home === '') {
            $this->markTestSkipped('HOME is unset');
        }
        $this->assertSame(rtrim(strtr($home, '\\', '/'), '/') . '/switon-home-probe', $this->filesystem->resolve('~/switon-home-probe'));
    }

    public function testExistsChecksFileExistence(): void
    {
        $testFile = $this->testDir . '/test.txt';
        file_put_contents($testFile, 'test');

        $this->assertTrue($this->filesystem->exists('@test/test.txt'));
        $this->assertFalse($this->filesystem->exists('@test/nonexistent.txt'));
    }

    public function testExistsChecksDirectoryExistence(): void
    {
        $this->assertTrue($this->filesystem->exists('@test'));
        $this->assertFalse($this->filesystem->exists('@test/nonexistent'));
    }

    public function testSizeReturnsFileSize(): void
    {
        $testFile = $this->testDir . '/test.txt';
        $content = 'test content';
        file_put_contents($testFile, $content);

        $this->assertSame(strlen($content), $this->filesystem->size('@test/test.txt'));
    }

    public function testSizeReturnsNullForNonExistentFile(): void
    {
        $this->assertNull($this->filesystem->size('@test/nonexistent.txt'));
    }

    public function testWriteCreatesFile(): void
    {
        $this->filesystem->write('@test/test.txt', 'test content');

        $this->assertTrue($this->filesystem->exists('@test/test.txt'));
        $this->assertSame('test content', file_get_contents($this->testDir . '/test.txt'));
    }

    public function testWriteLeavesNoTempSibling(): void
    {
        $this->filesystem->write('@test/test.txt', 'content');

        $this->assertSame(['test.txt'], $this->entriesInTestDir(), 'atomic write must clean up its temp file');
    }

    public function testWriteReplacesExistingFileWithoutTruncationWindow(): void
    {
        $this->filesystem->write('@test/test.txt', 'previous body that is longer');
        $this->filesystem->write('@test/test.txt', 'new');

        $this->assertSame('new', file_get_contents($this->testDir . '/test.txt'));
        $this->assertSame(['test.txt'], $this->entriesInTestDir());
    }

    public function testReadReadsFileContent(): void
    {
        $content = 'test content';
        file_put_contents($this->testDir . '/test.txt', $content);

        $this->assertSame($content, $this->filesystem->read('@test/test.txt'));
    }

    public function testLinesReadsTrimmedNonEmptyLines(): void
    {
        file_put_contents($this->testDir . '/data.jsonl', "first\n\n second \r\nthird\r\n");

        $this->assertSame(['first', 'second', 'third'], $this->filesystem->lines('@test/data.jsonl'));
    }

    public function testLinesReturnsEmptyArrayForEmptyFile(): void
    {
        file_put_contents($this->testDir . '/empty.jsonl', '');

        $this->assertSame([], $this->filesystem->lines('@test/empty.jsonl'));
    }

    public function testLinesThrowsExceptionForNonExistentFile(): void
    {
        $this->expectException(FileNotFoundException::class);
        $this->filesystem->lines('@test/nonexistent.txt');
    }

    public function testMkdirCreatesDirectory(): void
    {
        $this->filesystem->mkdir('@test/newdir');

        $this->assertTrue($this->filesystem->exists('@test/newdir'));
        $this->assertDirectoryExists($this->testDir . '/newdir');
    }

    public function testDeleteRemovesFile(): void
    {
        $testFile = $this->testDir . '/test.txt';
        file_put_contents($testFile, 'test');

        $this->filesystem->delete('@test/test.txt');
        $this->assertFalse($this->filesystem->exists('@test/test.txt'));
    }

    public function testDeleteMissingFileIsQuiet(): void
    {
        $this->filesystem->delete('@test/missing.txt');

        $this->assertFalse($this->filesystem->exists('@test/missing.txt'));
    }

    public function testAppendAppendsDataToFile(): void
    {
        $this->filesystem->write('@test/test.txt', 'initial');
        $this->filesystem->append('@test/test.txt', ' appended');

        $this->assertSame('initial appended', $this->filesystem->read('@test/test.txt'));
    }

    public function testAppendJsonlSerializesValueAndTrailingNewline(): void
    {
        $this->filesystem->appendJsonl('@test/events.jsonl', ['id' => 1, 'ok' => true]);
        $this->filesystem->appendJsonl('@test/events.jsonl', ['id' => 2]);

        $this->assertSame(
            ['{"id":1,"ok":true}', '{"id":2}'],
            $this->filesystem->lines('@test/events.jsonl'),
        );
    }

    public function testReadJsonlReturnsEmptyListWhenMissing(): void
    {
        $this->assertSame([], $this->filesystem->readJsonl('@test/missing.jsonl'));
    }

    public function testReadJsonlParsesEachLine(): void
    {
        $this->filesystem->appendJsonl('@test/events.jsonl', ['id' => 1, 'ok' => true]);
        $this->filesystem->appendJsonl('@test/events.jsonl', ['id' => 2]);

        $this->assertSame(
            [['id' => 1, 'ok' => true], ['id' => 2]],
            $this->filesystem->readJsonl('@test/events.jsonl'),
        );
    }

    public function testReadJsonlDropsTornTrailingLine(): void
    {
        $this->filesystem->appendJsonl('@test/events.jsonl', ['id' => 1]);
        $this->filesystem->append('@test/events.jsonl', '{"id":2,"hal');

        $this->assertSame([['id' => 1]], $this->filesystem->readJsonl('@test/events.jsonl'));
    }

    public function testReadJsonlRejectsCorruptionBeforeTheLastLine(): void
    {
        $this->filesystem->appendJsonl('@test/events.jsonl', ['id' => 1]);
        $this->filesystem->append('@test/events.jsonl', '{"id":2,"hal' . "\n");
        $this->filesystem->appendJsonl('@test/events.jsonl', ['id' => 3]);

        $this->expectException(JsonException::class);
        $this->filesystem->readJsonl('@test/events.jsonl');
    }

    public function testWriteJsonlReplacesFileWithOneCompactLineEach(): void
    {
        $this->filesystem->appendJsonl('@test/events.jsonl', ['id' => 0]);
        $this->filesystem->writeJsonl('@test/events.jsonl', [['id' => 1], ['id' => 2]]);

        $this->assertSame("{\"id\":1}\n{\"id\":2}\n", file_get_contents($this->testDir . '/events.jsonl'));
        $this->assertSame([['id' => 1], ['id' => 2]], $this->filesystem->readJsonl('@test/events.jsonl'));
    }

    public function testWriteJsonlEmptyListClearsFile(): void
    {
        $this->filesystem->appendJsonl('@test/events.jsonl', ['id' => 1]);
        $this->filesystem->writeJsonl('@test/events.jsonl', []);

        $this->assertSame('', file_get_contents($this->testDir . '/events.jsonl'));
        $this->assertSame([], $this->filesystem->readJsonl('@test/events.jsonl'));
    }

    public function testWriteJsonPrettyPrintsWithTrailingNewline(): void
    {
        $this->filesystem->writeJson('@test/doc.json', ['a' => 1, 'b' => 'x']);

        $raw = file_get_contents($this->testDir . '/doc.json');
        $this->assertSame("{\n    \"a\": 1,\n    \"b\": \"x\"\n}\n", $raw);
        $this->assertSame(['a' => 1, 'b' => 'x'], $this->filesystem->readJson('@test/doc.json'));
    }

    public function testWriteJsonCompactWhenOptionsZero(): void
    {
        $this->filesystem->writeJson('@test/compact.json', ['a' => 1], 0);

        $this->assertSame("{\"a\":1}\n", file_get_contents($this->testDir . '/compact.json'));
    }

    public function testReadJsonReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->filesystem->readJson('@test/missing.json'));
    }

    public function testReadJsonThrowsOnInvalidJson(): void
    {
        file_put_contents($this->testDir . '/bad.json', '{');

        $this->expectException(\Switon\Core\Exception\JsonException::class);
        $this->filesystem->readJson('@test/bad.json');
    }

    public function testAppendCreatesFileIfNotExists(): void
    {
        $this->filesystem->append('@test/new.txt', 'content');

        $this->assertSame('content', $this->filesystem->read('@test/new.txt'));
    }

    public function testMoveMovesFile(): void
    {
        $this->filesystem->write('@test/source.txt', 'content');
        $this->filesystem->move('@test/source.txt', '@test/dest.txt');

        $this->assertFalse($this->filesystem->exists('@test/source.txt'));
        $this->assertTrue($this->filesystem->exists('@test/dest.txt'));
        $this->assertSame('content', $this->filesystem->read('@test/dest.txt'));
    }

    public function testMoveOverwritesWhenEnabled(): void
    {
        $this->filesystem->write('@test/source.txt', 'new content');
        $this->filesystem->write('@test/dest.txt', 'old content');
        $this->filesystem->move('@test/source.txt', '@test/dest.txt', true);

        $this->assertSame('new content', $this->filesystem->read('@test/dest.txt'));
    }

    public function testRealpathCanonicalizesAliasAndResolvesSymlink(): void
    {
        $this->filesystem->write('@test/target.txt', 'x');
        $target = realpath($this->testDir . '/target.txt');
        $this->assertIsString($target);
        $this->assertSame($target, $this->filesystem->realpath('@test/target.txt'));
        $this->assertNull($this->filesystem->realpath('@test/missing.txt'));

        $link = $this->testDir . '/link.txt';
        if (!@symlink($target, $link)) {
            return;
        }

        $this->assertTrue($this->filesystem->isLink('@test/link.txt'));
        $this->assertFalse($this->filesystem->isLink('@test/target.txt'));
        $this->assertSame($target, $this->filesystem->realpath('@test/link.txt'));
    }

    public function testIsReadableReflectsFilePresence(): void
    {
        $this->filesystem->write('@test/a.txt', 'x');

        $this->assertTrue($this->filesystem->isReadable('@test/a.txt'));
        $this->assertFalse($this->filesystem->isReadable('@test/missing.txt'));
    }

    public function testIsDirChecksIfPathIsDirectory(): void
    {
        $this->filesystem->mkdir('@test/subdir');
        $this->filesystem->write('@test/file.txt', 'content');

        $this->assertTrue($this->filesystem->isDir('@test/subdir'));
        $this->assertFalse($this->filesystem->isDir('@test/file.txt'));
        $this->assertTrue($this->filesystem->isFile('@test/file.txt'));
        $this->assertFalse($this->filesystem->isFile('@test/subdir'));
    }

    public function testRmdirRemovesDirectory(): void
    {
        $this->filesystem->mkdir('@test/subdir');
        $this->filesystem->write('@test/subdir/file.txt', 'content');

        $this->filesystem->rmdir('@test/subdir');

        $this->assertFalse($this->filesystem->exists('@test/subdir'));
    }

    public function testRmdirSilentlyReturnsIfNotExists(): void
    {
        $this->assertFalse($this->filesystem->exists('@test/nonexistent'));
        $this->filesystem->rmdir('@test/nonexistent');
        $this->assertFalse($this->filesystem->exists('@test/nonexistent'));
    }

    public function testCopyCopiesFile(): void
    {
        $this->filesystem->write('@test/source.txt', 'content');
        $this->filesystem->copy('@test/source.txt', '@test/dest.txt');

        $this->assertTrue($this->filesystem->exists('@test/source.txt'));
        $this->assertTrue($this->filesystem->exists('@test/dest.txt'));
        $this->assertSame('content', $this->filesystem->read('@test/dest.txt'));
    }

    public function testCopyCopiesDirectoryRecursively(): void
    {
        $this->filesystem->mkdir('@test/source');
        $this->filesystem->write('@test/source/file1.txt', 'content1');
        $this->filesystem->write('@test/source/file2.txt', 'content2');
        $this->filesystem->mkdir('@test/source/subdir');
        $this->filesystem->write('@test/source/subdir/file3.txt', 'content3');

        $this->filesystem->copy('@test/source', '@test/dest');

        $this->assertTrue($this->filesystem->exists('@test/dest/file1.txt'));
        $this->assertTrue($this->filesystem->exists('@test/dest/file2.txt'));
        $this->assertTrue($this->filesystem->exists('@test/dest/subdir/file3.txt'));
        $this->assertSame('content1', $this->filesystem->read('@test/dest/file1.txt'));
        $this->assertSame('content3', $this->filesystem->read('@test/dest/subdir/file3.txt'));
    }

    public function testCopyOverwritesWhenEnabled(): void
    {
        $this->filesystem->write('@test/source.txt', 'new content');
        $this->filesystem->write('@test/dest.txt', 'old content');
        $this->filesystem->copy('@test/source.txt', '@test/dest.txt', true);

        $this->assertSame('new content', $this->filesystem->read('@test/dest.txt'));
    }

    public function testGlobFindsMatchingFiles(): void
    {
        $this->filesystem->write('@test/file1.txt', 'content');
        $this->filesystem->write('@test/file2.txt', 'content');
        $this->filesystem->write('@test/other.log', 'content');

        $results = $this->filesystem->glob('@test/*.txt');
        $this->assertCount(2, $results);
    }

    public function testFilesReturnsOnlyFiles(): void
    {
        $this->filesystem->write('@test/file1.txt', 'content');
        $this->filesystem->write('@test/file2.txt', 'content');
        $this->filesystem->mkdir('@test/subdir');

        $files = $this->filesystem->files('@test');
        $this->assertCount(2, $files);
        foreach ($files as $file) {
            $this->assertFileExists($file);
        }
    }

    public function testDirectoriesReturnsOnlyDirectories(): void
    {
        $this->filesystem->mkdir('@test/dir1');
        $this->filesystem->mkdir('@test/dir2');
        $this->filesystem->write('@test/file.txt', 'content');

        $dirs = $this->filesystem->directories('@test');
        $this->assertGreaterThanOrEqual(2, count($dirs));
        foreach ($dirs as $dir) {
            $this->assertDirectoryExists($dir);
        }
    }

    public function testListReturnsDirectoryContents(): void
    {
        $this->filesystem->write('@test/file1.txt', 'content');
        $this->filesystem->write('@test/file2.txt', 'content');
        $this->filesystem->mkdir('@test/subdir');

        $items = $this->filesystem->list('@test');
        $this->assertCount(3, $items);
        $this->assertContains('file1.txt', $items);
        $this->assertContains('file2.txt', $items);
        $this->assertContains('subdir', $items);
    }

    public function testMtimeReturnsModificationTime(): void
    {
        $this->filesystem->write('@test/file.txt', 'content');
        $mtime = $this->filesystem->mtime('@test/file.txt');

        $this->assertIsInt($mtime);
        $this->assertGreaterThan(0, $mtime);
    }

    public function testMtimeReturnsNullForNonExistentFile(): void
    {
        $this->assertNull($this->filesystem->mtime('@test/nonexistent.txt'));
    }

    public function testChmodChangesFilePermissions(): void
    {
        $this->filesystem->write('@test/file.txt', 'content');
        $this->filesystem->chmod('@test/file.txt', 0644);

        $perms = fileperms($this->testDir . '/file.txt') & 0777;
        $this->assertSame(0644, $perms);
    }

    public function testDeleteHandlesWildcardPatterns(): void
    {
        $this->filesystem->write('@test/file1.txt', 'content');
        $this->filesystem->write('@test/file2.txt', 'content');
        $this->filesystem->write('@test/other.log', 'content');

        $this->filesystem->delete('@test/*.txt');

        $this->assertFalse($this->filesystem->exists('@test/file1.txt'));
        $this->assertFalse($this->filesystem->exists('@test/file2.txt'));
        $this->assertTrue($this->filesystem->exists('@test/other.log'));
    }

    public function testReadThrowsExceptionForNonExistentFile(): void
    {
        $this->expectException(FileNotFoundException::class);
        $this->filesystem->read('@test/nonexistent.txt');
    }

    public function testMoveThrowsExceptionWhenDestinationExists(): void
    {
        $this->filesystem->write('@test/source.txt', 'content');
        $this->filesystem->write('@test/dest.txt', 'existing');

        $this->expectException(RuntimeException::class);
        $this->filesystem->move('@test/source.txt', '@test/dest.txt');
    }

    public function testCopyThrowsExceptionWhenDestinationExists(): void
    {
        $this->filesystem->write('@test/source.txt', 'content');
        $this->filesystem->write('@test/dest.txt', 'existing');

        $this->expectException(RuntimeException::class);
        $this->filesystem->copy('@test/source.txt', '@test/dest.txt');
    }

    public function testCopyThrowsExceptionWhenSourceDoesNotExist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->filesystem->copy('@test/nonexistent.txt', '@test/dest.txt');
    }

    public function testListThrowsExceptionForNonExistentDirectory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->filesystem->list('@test/nonexistent');
    }

    public function testListThrowsWhenPathIsAFile(): void
    {
        $this->filesystem->write('@test/not-a-dir.txt', 'x');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to list directory');

        $this->filesystem->list('@test/not-a-dir.txt');
    }

    public function testChmodThrowsWhenPathDoesNotExist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to chmod');

        $this->filesystem->chmod('@test/definitely-missing.bin', 0644);
    }

    public function testMkdirHandlesExistingDirectory(): void
    {
        $this->filesystem->mkdir('@test/existing');
        $this->filesystem->mkdir('@test/existing');
        $this->assertTrue($this->filesystem->exists('@test/existing'));
    }

    public function testMkdirCreatesNestedDirectories(): void
    {
        $this->filesystem->mkdir('@test/level1/level2/level3');

        $this->assertTrue($this->filesystem->exists('@test/level1'));
        $this->assertTrue($this->filesystem->exists('@test/level1/level2'));
        $this->assertTrue($this->filesystem->exists('@test/level1/level2/level3'));
    }

    public function testMoveAppendsBasenameWhenDestinationEndsWithSlash(): void
    {
        $this->filesystem->write('@test/source.txt', 'content');
        $this->filesystem->mkdir('@test/destdir');
        $this->filesystem->move('@test/source.txt', '@test/destdir/');

        $this->assertTrue($this->filesystem->exists('@test/destdir/source.txt'));
        $this->assertFalse($this->filesystem->exists('@test/source.txt'));
    }

    public function testGlobHandlesOnlyDirFlag(): void
    {
        $this->filesystem->write('@test/file.txt', 'content');
        $this->filesystem->mkdir('@test/subdir');

        $results = $this->filesystem->glob('@test/*', GLOB_ONLYDIR);
        $this->assertGreaterThanOrEqual(1, count($results));
        foreach ($results as $result) {
            $this->assertDirectoryExists($result);
        }
    }

    public function testFilesHandlesWildcardPatterns(): void
    {
        $this->filesystem->write('@test/file1.txt', 'content');
        $this->filesystem->write('@test/file2.txt', 'content');
        $this->filesystem->write('@test/other.log', 'content');

        $files = $this->filesystem->files('@test/*.txt');
        $this->assertCount(2, $files);
    }

    public function testDirectoriesHandlesWildcardPatterns(): void
    {
        $this->filesystem->mkdir('@test/dir1');
        $this->filesystem->mkdir('@test/dir2');
        $this->filesystem->write('@test/file.txt', 'content');

        $dirs = $this->filesystem->directories('@test/dir*');
        $this->assertGreaterThanOrEqual(2, count($dirs));
    }

    public function testCopyCreatesParentDirectories(): void
    {
        $this->filesystem->write('@test/source.txt', 'content');
        $this->filesystem->copy('@test/source.txt', '@test/nested/path/dest.txt');

        $this->assertTrue($this->filesystem->exists('@test/nested/path/dest.txt'));
        $this->assertSame('content', $this->filesystem->read('@test/nested/path/dest.txt'));
    }

    public function testCopyHandlesNestedSubdirectories(): void
    {
        $this->filesystem->mkdir('@test/source/level1/level2');
        $this->filesystem->write('@test/source/file1.txt', 'content1');
        $this->filesystem->write('@test/source/level1/file2.txt', 'content2');
        $this->filesystem->write('@test/source/level1/level2/file3.txt', 'content3');

        $this->filesystem->copy('@test/source', '@test/dest');

        $this->assertTrue($this->filesystem->exists('@test/dest/file1.txt'));
        $this->assertTrue($this->filesystem->exists('@test/dest/level1/file2.txt'));
        $this->assertTrue($this->filesystem->exists('@test/dest/level1/level2/file3.txt'));
    }

    public function testCopyHandlesOverwriteForDirectories(): void
    {
        $this->filesystem->mkdir('@test/source/subdir');
        $this->filesystem->write('@test/source/subdir/file.txt', 'new content');
        $this->filesystem->mkdir('@test/dest/subdir');
        $this->filesystem->write('@test/dest/subdir/file.txt', 'old content');

        $this->filesystem->copy('@test/source', '@test/dest', true);

        $this->assertSame('new content', $this->filesystem->read('@test/dest/subdir/file.txt'));
    }

    public function testRmdirHandlesNestedDirectories(): void
    {
        $this->filesystem->mkdir('@test/level1/level2/level3');
        $this->filesystem->write('@test/level1/file1.txt', 'content');
        $this->filesystem->write('@test/level1/level2/file2.txt', 'content');
        $this->filesystem->write('@test/level1/level2/level3/file3.txt', 'content');

        $this->filesystem->rmdir('@test/level1');

        $this->assertFalse($this->filesystem->exists('@test/level1'));
    }

    public function testListHandlesDifferentSortingOrders(): void
    {
        $this->filesystem->write('@test/a.txt', 'content');
        $this->filesystem->write('@test/b.txt', 'content');
        $this->filesystem->write('@test/c.txt', 'content');

        $asc = $this->filesystem->list('@test');
        $desc = $this->filesystem->list('@test', SCANDIR_SORT_DESCENDING);
        $none = $this->filesystem->list('@test', SCANDIR_SORT_NONE);

        $this->assertCount(3, $asc);
        $this->assertCount(3, $desc);
        $this->assertCount(3, $none);
    }

    public function testGlobReturnsEmptyForNonMatchingPattern(): void
    {
        $this->filesystem->write('@test/file.txt', 'content');

        $results = $this->filesystem->glob('@test/*.log');
        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    public function testSizeReturnsCorrectSizeForFiles(): void
    {
        $content1 = str_repeat('a', 100);
        $content2 = str_repeat('b', 1000);

        $this->filesystem->write('@test/file1.txt', $content1);
        $this->filesystem->write('@test/file2.txt', $content2);

        $this->assertSame(100, $this->filesystem->size('@test/file1.txt'));
        $this->assertSame(1000, $this->filesystem->size('@test/file2.txt'));
    }

    public function testWriteCreatesParentDirectories(): void
    {
        $this->filesystem->write('@test/nested/path/file.txt', 'content');

        $this->assertTrue($this->filesystem->exists('@test/nested/path/file.txt'));
        $this->assertSame('content', $this->filesystem->read('@test/nested/path/file.txt'));
    }

    public function testAppendCreatesParentDirectories(): void
    {
        $this->filesystem->append('@test/nested/path/file.txt', 'content');

        $this->assertTrue($this->filesystem->exists('@test/nested/path/file.txt'));
        $this->assertSame('content', $this->filesystem->read('@test/nested/path/file.txt'));
    }

    public function testPharGlobMatchesOneSegmentAndNestedPatterns(): void
    {
        $root = $this->pharRoot();

        $this->assertSame(
            ['Controller/HomeController.php'],
            $this->pharRelative($this->filesystem->glob('@phar/Controller/*Controller.php')),
        );
        $this->assertSame(
            [
                'Areas/Admin/Controller/RoleController.php',
                'Areas/Admin/Controller/UserController.php',
                'Areas/Blog/Controller/PostController.php',
            ],
            $this->pharRelative($this->filesystem->glob('@phar/Areas/*/Controller/*Controller.php')),
        );
        $this->assertSame(
            ['View/layout/main.phtml'],
            $this->pharRelative($this->filesystem->glob('@phar/View/*/*')),
        );
        $this->assertSame(
            ['nested/a/stats.json', 'nested/b/stats.json'],
            $this->pharRelative($this->filesystem->glob('@phar/nested/*/stats.json')),
        );
        $this->assertSame([], $this->filesystem->glob('@phar/Missing/*'));
        $this->assertSame("<?php // user\n", $this->filesystem->read('@phar/Areas/Admin/Controller/UserController.php'));
        $this->assertSame(1141214400, $this->filesystem->mtime($root . '/index.html'));
    }

    public function testPharGlobSkipsDotfilesAndHonorsOnlyDir(): void
    {
        $this->pharRoot();

        $this->assertSame(
            ['Areas', 'Controller', 'View', 'app.js', 'events.jsonl', 'index.html', 'nested', 'notes.txt'],
            $this->pharRelative($this->filesystem->glob('@phar/*')),
        );
        $this->assertSame(['.hidden'], $this->pharRelative($this->filesystem->glob('@phar/.*')));
        $this->assertSame(
            ['Areas/Admin', 'Areas/Blog'],
            $this->pharRelative($this->filesystem->glob('@phar/Areas/*', GLOB_ONLYDIR)),
        );
    }

    public function testPharFilesDirectoriesAndListReadTheArchive(): void
    {
        $this->pharRoot();

        $this->assertSame(
            ['Controller/HomeController.php', 'Controller/Skip.txt'],
            $this->pharRelative($this->filesystem->files('@phar/Controller')),
        );
        $this->assertSame(
            ['Areas/Admin', 'Areas/Blog'],
            $this->pharRelative($this->filesystem->directories('@phar/Areas')),
        );
        $listed = $this->filesystem->list('@phar');
        sort($listed);
        $this->assertSame(
            ['.hidden', 'Areas', 'Controller', 'View', 'app.js', 'events.jsonl', 'index.html', 'nested', 'notes.txt'],
            $listed,
        );
    }

    public function testPharReadMethods(): void
    {
        $root = $this->pharRoot();

        $this->assertSame($root . '/index.html', $this->filesystem->resolve('@phar/index.html'));
        $this->assertTrue($this->filesystem->exists('@phar/index.html'));
        $this->assertTrue($this->filesystem->exists('@phar/Areas/Admin'));
        $this->assertFalse($this->filesystem->exists('@phar/missing.txt'));
        $this->assertTrue($this->filesystem->isDir('@phar/Areas'));
        $this->assertFalse($this->filesystem->isDir('@phar/index.html'));
        $this->assertTrue($this->filesystem->isFile('@phar/index.html'));
        $this->assertFalse($this->filesystem->isFile('@phar/Areas'));
        $this->assertSame($root . '/index.html', $this->filesystem->realpath('@phar/index.html'));
        $this->assertSame($root . '/index.html', $this->filesystem->realpath($root . '/./index.html'));
        $this->assertNull($this->filesystem->realpath($root . '/foo/../index.html'));
        $this->assertNull($this->filesystem->realpath('@phar/missing.txt'));
        $this->assertTrue($this->filesystem->isReadable('@phar/index.html'));
        $this->assertFalse($this->filesystem->isReadable('@phar/missing.txt'));
        $this->assertFalse($this->filesystem->isLink('@phar/index.html'));
        $this->assertSame(12, $this->filesystem->size('@phar/index.html'));
        $this->assertNull($this->filesystem->size('@phar/missing.txt'));
        $this->assertSame("<h1>hi</h1>\n", $this->filesystem->read('@phar/index.html'));
        $this->assertSame(['alpha', 'beta'], $this->filesystem->lines('@phar/notes.txt'));
        $this->assertSame([], $this->filesystem->readJson('@phar/nested/a/stats.json'));
        $this->assertNull($this->filesystem->readJson('@phar/missing.json'));
        $this->assertSame([], $this->filesystem->readJsonl('@phar/missing.jsonl'));
        $this->assertSame([['id' => 1], ['id' => 2]], $this->filesystem->readJsonl('@phar/events.jsonl'));
        $this->assertSame(1141214400, $this->filesystem->mtime('@phar/notes.txt'));
        $this->assertNull($this->filesystem->mtime('@phar/missing.txt'));

        try {
            $this->filesystem->read('@phar/missing.txt');
            $this->fail('missing file must not be readable');
        } catch (FileNotFoundException) {
        }

        $this->filesystem->copy('@phar/index.html', '@test/index.html');
        $this->assertSame("<h1>hi</h1>\n", $this->filesystem->read('@test/index.html'));
        $this->filesystem->copy('@phar/Areas/Blog', '@test/Blog');
        $this->assertSame("<?php // post\n", $this->filesystem->read('@test/Blog/Controller/PostController.php'));
        $this->assertSame("blog\n", $this->filesystem->read('@test/Blog/View/index.phtml'));
    }

    public function testPharRejectsWritesWithoutChangingTheArchive(): void
    {
        $this->pharRoot();
        $archive = dirname(__DIR__) . '/Fixtures/filesystem.phar';
        $before = file_get_contents($archive);

        $writes = [
            fn () => $this->filesystem->write('@phar/index.html', 'x'),
            fn () => $this->filesystem->append('@phar/index.html', 'x'),
            fn () => $this->filesystem->appendJsonl('@phar/events.jsonl', ['id' => 3]),
            fn () => $this->filesystem->writeJson('@phar/new.json', ['a' => 1]),
            fn () => $this->filesystem->delete('@phar/index.html'),
            fn () => $this->filesystem->delete('@phar/*.html'),
            fn () => $this->filesystem->move('@phar/index.html', '@test/moved.html'),
            fn () => $this->filesystem->mkdir('@phar/NewDir'),
            fn () => $this->filesystem->rmdir('@phar/View'),
            fn () => $this->filesystem->touch('@phar/index.html', 1141214400),
            fn () => $this->filesystem->chmod('@phar/index.html', 0644),
            fn () => $this->filesystem->copy('@phar/app.js', '@phar/app-copy.js'),
            fn () => $this->filesystem->move('@test/index.html', '@phar/moved.html'),
        ];
        foreach ($writes as $write) {
            try {
                $write();
                $this->fail('write against the archive must fail');
            } catch (NotSupportedException) {
            } catch (Throwable $e) {
                $this->fail($e::class . ': ' . $e->getMessage());
            }
        }

        $this->assertSame($before, file_get_contents($archive));
        $this->assertSame("<h1>hi</h1>\n", $this->filesystem->read('@phar/index.html'));
    }

    /**
     * Extracting from an archive is {@see FilesystemInterface::copy()}; `move()` keeps its
     * "source disappears" contract and refuses a `phar://` source instead of silently degrading.
     */
    public function testPharMoveRefusesToDegradeToCopy(): void
    {
        $this->pharRoot();
        $archive = dirname(__DIR__) . '/Fixtures/filesystem.phar';
        $before = file_get_contents($archive);

        try {
            $this->filesystem->move('@phar/index.html', '@test/moved.html');
            $this->fail('move() must not silently become copy() for a phar source');
        } catch (NotSupportedException) {
        }

        $this->assertFalse($this->filesystem->exists('@test/moved.html'));
        $this->assertSame($before, file_get_contents($archive));
        $this->assertSame("<h1>hi</h1>\n", $this->filesystem->read('@phar/index.html'));
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    protected function pharRelative(array $paths): array
    {
        $prefix = $this->pharRoot . '/';
        $relative = [];
        foreach ($paths as $path) {
            $relative[] = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
        }

        return $relative;
    }

    protected function pharRoot(): string
    {
        $this->pharRoot = 'phar://' . dirname(__DIR__) . '/Fixtures/filesystem.phar';
        $this->pathAlias->set('@phar', $this->pharRoot);

        return $this->pharRoot;
    }
}
