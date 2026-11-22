<?php

declare(strict_types=1);

namespace Switon\Cdp\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Switon\Cdp\Paths;
use Switon\Core\Exception\CreateDirectoryFailedException;
use Switon\Core\Exception\FileNotFoundException;
use Switon\Core\Exception\RuntimeException;
use Switon\Core\Filesystem;
use Switon\Core\PathAlias;

use const PHP_OS_FAMILY;

class PathsTest extends TestCase
{
    #[Test]
    public function defaultUserDataDirSplitsHeadlessAndHeadedUnderSwitonChrome(): void
    {
        $headless = Paths::defaultUserDataDir(true);
        $headed = Paths::defaultUserDataDir(false);
        $this->assertNotSame('', $headless);
        $this->assertNotSame($headless, $headed);
        $this->assertStringContainsString('.switon', $headless);
        $this->assertStringContainsString('chrome', $headless);
        $this->assertStringEndsWith('headless', $headless);
        $this->assertStringEndsWith('headed', $headed);
        $this->assertStringStartsWith(Paths::chromeRoot(), $headless);
        $this->assertStringStartsWith(Paths::chromeRoot(), $headed);

        $system = Paths::systemUserDataDir();
        if ($system !== '') {
            $this->assertNotSame($system, $headless);
            $this->assertNotSame($system, $headed);
        }
    }

    #[Test]
    public function defaultDebuggingPortSplitsByMode(): void
    {
        $this->assertSame(59222, Paths::defaultDebuggingPort(true));
        $this->assertSame(59223, Paths::defaultDebuggingPort(false));
        $this->assertSame(Paths::PORT_HEADLESS, Paths::defaultDebuggingPort(true));
        $this->assertSame(Paths::PORT_HEADED, Paths::defaultDebuggingPort(false));
    }

    #[Test]
    public function ensureStartScriptWritesThePlatformScriptIntoHeadedProfileDir(): void
    {
        $dir = sys_get_temp_dir() . '/switon-chrome-script-' . getmypid();
        @mkdir($dir, 0700, true);
        $script = $this->paths()->ensureStartScript($dir);
        $this->assertNotSame('', $script);
        $this->assertFileExists($script);
        $this->assertSame(Paths::startScriptPath($dir), $script);

        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('remote-debugging-port', $body);
        $this->assertStringContainsString('59223', $body);

        if (str_starts_with(strtolower(php_uname('s')), 'win')) {
            $this->assertStringEndsWith('start-cdp.ps1', $script);
        } else {
            $this->assertStringEndsWith('start-cdp.sh', $script);
            $this->assertTrue(is_executable($script));
            $this->assertStringContainsString('.switon/chrome/headed', $body);
        }
    }

    #[Test]
    public function displayPathReplacesTheHomePrefixWithATilde(): void
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '';
        if ($home === '') {
            $this->markTestSkipped('No HOME/USERPROFILE to redact.');
        }

        $sep = PHP_OS_FAMILY === 'Windows' ? '\\' : '/';
        $this->assertSame('~', Paths::displayPath($home));
        $this->assertSame(
            '~/.switon/chrome/headed',
            Paths::displayPath($home . $sep . '.switon' . $sep . 'chrome' . $sep . 'headed'),
        );
        $this->assertSame('/opt/google/chrome', Paths::displayPath('/opt/google/chrome'));
        $this->assertSame('', Paths::displayPath('  '));
    }

    #[Test]
    public function packageStartScriptShipsWithThePackage(): void
    {
        $script = Paths::packageStartScript();
        $this->assertFileExists($script);
        $this->assertStringStartsWith(dirname(__DIR__, 2), $script);
        $this->assertStringEndsWith(
            str_starts_with(strtolower(php_uname('s')), 'win') ? 'start-cdp.ps1' : 'start-cdp.sh',
            $script,
        );
    }

    #[Test]
    public function systemUserDataDirMatchesOsLayout(): void
    {
        $dir = Paths::systemUserDataDir();
        if ($dir === '') {
            $this->markTestSkipped('No HOME/LOCALAPPDATA for system Chrome path.');
        }

        $this->assertMatchesRegularExpression('/Chrome|chromium|google-chrome/i', $dir);
    }

    #[Test]
    public function ensureStartScriptReturnsEmptyWhenTheProfileCannotBeCreated(): void
    {
        $filesystem = new class () extends Filesystem {
            public function __construct()
            {
                $this->pathAlias = new PathAlias();
            }

            public function isDir(string $dir): bool
            {
                return false;
            }

            public function mkdir(string $dir, int $mode = 0755): void
            {
                CreateDirectoryFailedException::raise('denied');
            }
        };

        $this->assertSame('', $this->paths($filesystem)->ensureStartScript(sys_get_temp_dir() . '/switon-cdp-denied-profile'));
    }

    #[Test]
    public function ensureStartScriptGivesUpWhenTheTemplateCannotBeRead(): void
    {
        $missing = new class () extends Filesystem {
            public function __construct()
            {
                $this->pathAlias = new PathAlias();
            }

            public function isDir(string $dir): bool
            {
                return true;
            }

            public function isFile(string $file): bool
            {
                return false;
            }
        };
        $this->assertSame('', $this->paths($missing)->ensureStartScript('/profile'));

        $unread = new class () extends Filesystem {
            public function __construct()
            {
                $this->pathAlias = new PathAlias();
            }

            public function isDir(string $dir): bool
            {
                return true;
            }

            public function isFile(string $file): bool
            {
                return true;
            }

            public function read(string $file): string
            {
                FileNotFoundException::raise($file);
            }
        };
        $this->assertSame('', $this->paths($unread)->ensureStartScript('/profile'));
    }

    #[Test]
    public function ensureStartScriptKeepsTheScriptWhenTheWriteFails(): void
    {
        $dir = sys_get_temp_dir() . '/switon-cdp-script-write-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $dest = Paths::startScriptPath($dir);
        file_put_contents($dest, 'old');

        $filesystem = new class () extends Filesystem {
            public function __construct()
            {
                $this->pathAlias = new PathAlias();
            }

            public function isDir(string $dir): bool
            {
                return true;
            }

            public function isFile(string $file): bool
            {
                return true;
            }

            public function read(string $file): string
            {
                return str_contains($file, 'bin') ? 'template' : 'old';
            }

            public function write(string $file, string $content): void
            {
                RuntimeException::raise('denied');
            }
        };

        try {
            $this->assertSame($dest, $this->paths($filesystem)->ensureStartScript($dir));
        } finally {
            @unlink($dest);
            @rmdir($dir);
        }
    }

    #[Test]
    public function systemUserDataDirIsEmptyWithoutAHome(): void
    {
        $key = PHP_OS_FAMILY === 'Windows' ? 'LOCALAPPDATA' : 'HOME';
        $previous = getenv($key);
        putenv($key);
        try {
            $this->assertSame('', Paths::systemUserDataDir());
        } finally {
            if ($previous === false) {
                putenv($key);
            } else {
                putenv("{$key}={$previous}");
            }
        }
    }

    #[Test]
    public function detectPrefersAnExecutableGivenByTheEnvironment(): void
    {
        $dir = sys_get_temp_dir() . '/switon-chrome-bin-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $binary = $dir . '/chrome';
        file_put_contents($binary, "#!/bin/sh\nexit 0\n");
        chmod($binary, 0755);
        $previous = getenv('SWITON_CHROME_PATH');
        putenv('SWITON_CHROME_PATH=' . $binary);
        try {
            $this->assertSame($binary, Paths::detect());
            putenv('SWITON_CHROME_PATH=/no/such/chrome');
            $fallback = Paths::detect();
            $this->assertNotSame('/no/such/chrome', $fallback);
        } finally {
            if ($previous === false) {
                putenv('SWITON_CHROME_PATH');
            } else {
                putenv('SWITON_CHROME_PATH=' . $previous);
            }
            @unlink($binary);
            @rmdir($dir);
        }
    }

    #[Test]
    public function detectReturnsExecutableWhenChromeInstalled(): void
    {
        $path = Paths::detect();
        if ($path === '') {
            $this->markTestSkipped('Chrome/Chromium not installed on this machine.');
        }

        $this->assertFileExists($path);
    }

    #[Test]
    public function ensureStartScriptUsesTheDefaultDirAndKeepsGoingWhenTheOldCopyIsUnreadable(): void
    {
        $absent = new class () extends Filesystem {
            public function __construct()
            {
                $this->pathAlias = new PathAlias();
            }

            public function isDir(string $path): bool
            {
                return true;
            }

            public function isFile(string $path): bool
            {
                return false;
            }
        };
        $this->assertSame('', $this->paths($absent)->ensureStartScript(''));

        $dir = sys_get_temp_dir() . '/switon-paths-' . uniqid('', true);
        mkdir($dir);
        $dest = Paths::startScriptPath($dir);
        touch($dest);
        $unreadable = new class ($dest) extends Filesystem {
            public function __construct(private string $dest)
            {
                $this->pathAlias = new PathAlias();
            }

            public function isDir(string $path): bool
            {
                return true;
            }

            public function isFile(string $path): bool
            {
                return true;
            }

            public function read(string $path): string
            {
                if ($path === $this->dest) {
                    FileNotFoundException::raise($path);
                }

                return 'template';
            }
        };
        try {
            $this->assertSame($dest, $this->paths($unreadable)->ensureStartScript($dir));
        } finally {
            @unlink($dest);
            @rmdir($dir);
        }
    }

    #[Test]
    public function pathsWithoutAHomeDoNotUseTheLoginDirectory(): void
    {
        $previous = getenv('HOME');
        $previousProfile = getenv('USERPROFILE');
        putenv('HOME');
        putenv('USERPROFILE');
        try {
            $this->assertSame('/tmp/file', Paths::displayPath('/tmp/file'));
            $this->assertStringEndsWith('switon-chrome', Paths::chromeRoot());
            $this->assertStringContainsString('start-cdp', Paths::startScriptPath(''));
        } finally {
            if ($previous === false) {
                putenv('HOME');
            } else {
                putenv('HOME=' . $previous);
            }
            if ($previousProfile === false) {
                putenv('USERPROFILE');
            } else {
                putenv('USERPROFILE=' . $previousProfile);
            }
        }
    }

    protected function paths(?Filesystem $filesystem = null): Paths
    {
        $filesystem ??= new class () extends Filesystem {
            public function __construct()
            {
                $this->pathAlias = new PathAlias();
            }
        };

        return new class ($filesystem) extends Paths {
            public function __construct(Filesystem $filesystem)
            {
                $this->filesystem = $filesystem;
            }
        };
    }
}
