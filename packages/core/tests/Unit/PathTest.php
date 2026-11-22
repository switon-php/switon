<?php

declare(strict_types=1);

namespace Switon\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Core\Exception;
use Switon\Core\Path;

use function file_put_contents;
use function getenv;
use function is_dir;
use function is_string;
use function mkdir;
use function rtrim;
use function sys_get_temp_dir;
use function uniqid;

class PathTest extends TestCase
{
    public function testNormalizeSeparatorsAndSlashes(): void
    {
        $this->assertSame('a/b/c', Path::normalize('a\\b\\c'));
        $this->assertSame('a/b', Path::normalize('a//b///'));
        $this->assertSame('/', Path::normalize('/'));
        $this->assertSame('/a/b', Path::normalize('/a/b/'));
    }

    /**
     * A <code>scheme://</code> prefix keeps its slashes; only the remainder is normalized.
     */
    public function testNormalizePreservesWrapperScheme(): void
    {
        $this->assertSame('phar:///tmp/a.phar/x.txt', Path::normalize('phar:///tmp/a.phar/x.txt'));
        $this->assertSame('phar:///tmp/a.phar/x', Path::normalize('phar:///tmp/a.phar//x'));
        $this->assertSame('phar:///tmp/a.phar', Path::normalize('phar:///tmp/a.phar/'));
        $this->assertSame('phar://app.phar/resources/a.txt', Path::normalize('phar://app.phar/resources/a.txt'));
        $this->assertSame('PHAR:///tmp/a.phar/x', Path::normalize('PHAR:///tmp/a.phar/x'));
        $this->assertSame('phar://C:/a.phar/x', Path::normalize('phar://C:/a.phar/x'));
        $this->assertSame('php://memory', Path::normalize('php://memory'));
    }

    public function testIsRelativeAcceptsSafePaths(): void
    {
        $this->assertTrue(Path::isRelative('a'));
        $this->assertTrue(Path::isRelative('a/b/c'));
        $this->assertTrue(Path::isRelative('foo.bar'));
        $this->assertTrue(Path::isRelative('tool-result/x.txt'));
    }

    public function testIsRelativeRejectsTraversalAndAbsolute(): void
    {
        $this->assertFalse(Path::isRelative(''));
        $this->assertFalse(Path::isRelative('.'));
        $this->assertFalse(Path::isRelative('./a'));
        $this->assertFalse(Path::isRelative('a/./b'));
        $this->assertFalse(Path::isRelative('..'));
        $this->assertFalse(Path::isRelative('../x'));
        $this->assertFalse(Path::isRelative('a/../b'));
        $this->assertFalse(Path::isRelative('foo..bar'));
        $this->assertFalse(Path::isRelative("/a\0b"));
        $this->assertFalse(Path::isRelative('/abs'));
        $this->assertFalse(Path::isRelative('C:/windows'));
        $this->assertFalse(Path::isRelative('C:\\windows'));
        // Wrapper paths must never be joined under another root.
        $this->assertFalse(Path::isRelative('phar:///tmp/a.phar/x.txt'));
        $this->assertFalse(Path::isRelative('PHAR:///tmp/a.phar/x'));
        $this->assertFalse(Path::isRelative('php://memory'));
    }

    public function testIsAbsolute(): void
    {
        $this->assertFalse(Path::isAbsolute(''));
        $this->assertFalse(Path::isAbsolute('relative.txt'));
        $this->assertFalse(Path::isAbsolute('a/b'));
        $this->assertFalse(Path::isAbsolute('C:'));
        $this->assertTrue(Path::isAbsolute('/abs/file.txt'));
        $this->assertTrue(Path::isAbsolute('\\abs\\file.txt'));
        $this->assertTrue(Path::isAbsolute('\\\\server\\share\\file.txt'));
        $this->assertTrue(Path::isAbsolute('C:/windows'));
        $this->assertTrue(Path::isAbsolute('C:\\windows'));
        $this->assertTrue(Path::isAbsolute('D:/file.txt'));
        $this->assertFalse(Path::isAbsolute('notadrive:\\file.txt'));
        // scheme:// wrappers are absolute; `phar:/single/x` is not a wrapper PHP accepts.
        $this->assertTrue(Path::isAbsolute('phar:///tmp/a.phar/x.txt'));
        $this->assertTrue(Path::isAbsolute('PHAR:///tmp/a.phar/x'));
        $this->assertTrue(Path::isAbsolute('phar://app.phar/resources/a.txt'));
        $this->assertFalse(Path::isAbsolute('phar:/single/x'));
    }

    public function testAbsoluteExpandsTildeAndRejectsRelative(): void
    {
        $home = getenv('HOME');
        if (!is_string($home) || $home === '') {
            $home = getenv('USERPROFILE');
        }
        if (!is_string($home) || $home === '') {
            $this->markTestSkipped('HOME/USERPROFILE is not set');
        }

        $home = rtrim(strtr($home, '\\', '/'), '/');
        $this->assertSame('/tmp/note.txt', Path::absolute('/tmp//note.txt'));
        $this->assertSame($home . '/note.txt', Path::absolute('~/note.txt'));
        $this->assertSame($home, Path::absolute('~'));
    }

    public function testAbsoluteRejectsNonAbsolute(): void
    {
        foreach (['', 'note.txt', '@alias/note.txt'] as $path) {
            try {
                Path::absolute($path);
                $this->fail('Expected absolute to reject "' . $path . '"');
            } catch (Exception $e) {
                $this->assertStringContainsString('Path must be absolute', $e->getMessage());
            }
        }
    }

    public function testTildeExpandsHome(): void
    {
        $home = getenv('HOME');
        if (!is_string($home) || $home === '') {
            $home = getenv('USERPROFILE');
        }
        if (!is_string($home) || $home === '') {
            $this->markTestSkipped('HOME/USERPROFILE is not set');
        }

        $home = rtrim(strtr($home, '\\', '/'), '/');
        $this->assertSame($home, Path::tilde('~'));
        $this->assertSame($home . '/www', Path::tilde('~/www'));
        $this->assertSame($home . '/www', Path::tilde('~\\www'));
        $this->assertSame('/abs', Path::tilde('/abs'));
        $this->assertSame('rel', Path::tilde('rel'));
    }

    public function testWithinLexical(): void
    {
        $this->assertTrue(Path::within('/root', '/root', true));
        $this->assertFalse(Path::within('/root', '/root', false));
        $this->assertTrue(Path::within('/root', '/root/a'));
        $this->assertTrue(Path::within('/root', '/root/a/b'));
        $this->assertFalse(Path::within('/root', '/rootx/a'));
        $this->assertFalse(Path::within('/root', '/elsewhere'));
        $this->assertTrue(Path::within('/root', '/root\\a'));
    }

    public function testToRelative(): void
    {
        $this->assertSame('', Path::toRelative('/root', '/root'));
        $this->assertSame('a/b', Path::toRelative('/root', '/root/a/b'));
        $this->assertNull(Path::toRelative('/root', '/elsewhere'));
        $this->assertNull(Path::toRelative('/root', '/rootx'));
    }

    public function testResolveUnderRoot(): void
    {
        $root = sys_get_temp_dir() . '/switon-path-' . uniqid('', true);
        mkdir($root, 0777, true);
        mkdir($root . '/sub', 0777, true);
        file_put_contents($root . '/sub/file.txt', 'x');

        $resolved = Path::resolve($root, 'sub/file.txt');
        $this->assertNotNull($resolved);
        $this->assertSame(realpath($root . '/sub/file.txt'), $resolved);

        $this->assertNull(Path::resolve($root, '../file.txt'));
        $this->assertNull(Path::resolve($root, 'foo..bar'));
        $this->assertNull(Path::resolve($root, 'missing.txt'));
        $this->assertNull(Path::resolve($root, '/abs'));
        $this->assertNull(Path::resolve($root, '.'));
        $this->assertNull(Path::resolve($root, 'sub/..'));

        $realRoot = realpath($root);
        $this->assertNotFalse($realRoot);
        $this->assertTrue(Path::within($realRoot, $realRoot, true));
        $this->assertFalse(Path::within($realRoot, $realRoot, false));
    }

    public function testResolveDirectory(): void
    {
        $root = sys_get_temp_dir() . '/switon-path-root-' . uniqid('', true);
        mkdir($root . '/d', 0777, true);
        $dir = Path::resolve($root, 'd', true);
        $this->assertNotNull($dir);
        $this->assertTrue(is_dir($dir));
    }

    public function testResolvePharRoot(): void
    {
        $root = Path::normalize('phar://' . dirname(__DIR__) . '/Fixtures/filesystem.phar');

        $this->assertSame($root . '/index.html', Path::resolve($root, 'index.html'));
        $this->assertSame($root . '/index.html', Path::resolve($root . '/.', 'index.html'));
        $this->assertNull(Path::resolve($root . '/foo/..', 'index.html'));
        $this->assertNull(Path::resolve($root, 'missing.txt'));
        $this->assertNull(Path::resolve($root, '../index.html'));
    }

    public function testPharCanonicalRejectsTraversalNullByteAndEmpty(): void
    {
        $root = Path::normalize('phar://' . dirname(__DIR__) . '/Fixtures/filesystem.phar');

        $this->assertSame($root, Path::pharCanonical($root . '/.'));
        $this->assertNull(Path::pharCanonical('phar://app.phar/..'));
        $this->assertNull(Path::pharCanonical("phar://app.phar/\0x"));
        $this->assertNull(Path::pharCanonical('not-phar'));
    }

    public function testWithinRejectsNullByteAndHonorsRootSlash(): void
    {
        $this->assertFalse(Path::within("/root\0", '/root/a'));
        $this->assertTrue(Path::within('/', '/tmp/a'));
        $this->assertFalse(Path::within('/', '/', false));
    }

    public function testResolveReturnsNullWhenRootDoesNotExist(): void
    {
        $missing = sys_get_temp_dir() . '/switon-missing-root-' . uniqid('', true);

        $this->assertNull(Path::resolve($missing, 'child.txt'));
    }
}
