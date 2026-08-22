<?php

declare(strict_types=1);

namespace Switon\Cdp;

use Switon\Core\Attribute\Autowired;
use Switon\Core\Exception\CreateDirectoryFailedException;
use Switon\Core\Exception\FileNotFoundException;
use Switon\Core\Exception\RuntimeException;
use Switon\Core\FilesystemInterface;

use function chmod;
use function dirname;
use function file_exists;
use function getenv;
use function is_executable;
use function is_string;
use function rtrim;
use function strlen;
use function str_replace;
use function str_starts_with;
use function substr;
use function sys_get_temp_dir;

use const DIRECTORY_SEPARATOR;
use const PHP_OS_FAMILY;

/**
 * Resolves Chrome/Chromium binary and profile paths.
 *
 * Chrome refuses DevTools remote debugging on the *default* user-data dir
 * ("DevTools remote debugging requires a non-default data directory").
 * Defaults live under `chromeRoot()` as two mode profiles:
 * {@code headless/} (background) and {@code headed/} (foreground).
 *
 * Role: every OS question goes through {@see Paths::isWindows()} or `PHP_OS_FAMILY`, so the
 * launch script name, path separator, and profile layout agree with {@see Launcher}.
 */
class Paths
{
    #[Autowired] protected FilesystemInterface $filesystem;

    /** Background CDP (search / silent fetch). */
    public const PORT_HEADLESS = 59222;

    /** Foreground CDP (human login / interactive automation). */
    public const PORT_HEADED = 59223;

    public static function detect(): string
    {
        $env = getenv('SWITON_CHROME_PATH') ?: getenv('CHROME_PATH');
        if (is_string($env) && $env !== '' && self::usable($env)) {
            return $env;
        }

        $candidates = match (PHP_OS_FAMILY) {
            'Darwin' => [
                '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
                '/Applications/Chromium.app/Contents/MacOS/Chromium',
                '/Applications/Google Chrome Canary.app/Contents/MacOS/Google Chrome Canary',
            ],
            'Windows' => [
                (getenv('PROGRAMFILES') ?: 'C:\\Program Files') . '\\Google\\Chrome\\Application\\chrome.exe',
                (getenv('PROGRAMFILES(X86)') ?: 'C:\\Program Files (x86)') . '\\Google\\Chrome\\Application\\chrome.exe',
                (getenv('LOCALAPPDATA') ?: '') . '\\Google\\Chrome\\Application\\chrome.exe',
            ],
            default => [
                '/usr/bin/google-chrome',
                '/usr/bin/google-chrome-stable',
                '/usr/bin/chromium',
                '/usr/bin/chromium-browser',
                '/snap/bin/chromium',
            ],
        };

        foreach ($candidates as $path) {
            if ($path !== '' && self::usable($path)) {
                return $path;
            }
        }

        return '';
    }

    /**
     * Path for exception and log text: `$HOME` becomes `~`, so a login name never appears.
     */
    public static function displayPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $home = rtrim(self::homeDir(), '/\\');
        if ($home === '') {
            return $path;
        }
        if ($path !== $home && !str_starts_with($path, $home . '/') && !str_starts_with($path, $home . '\\')) {
            return $path;
        }

        return '~' . str_replace('\\', '/', substr($path, strlen($home)));
    }

    /** Home-local root: {@code ~/.switon/chrome}. */
    public static function chromeRoot(): string
    {
        $home = self::homeDir();
        if ($home === '') {
            return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'switon-chrome';
        }

        return $home . self::sep() . '.switon' . self::sep() . 'chrome';
    }

    /**
     * Mode profile used for CDP (attach / launch).
     *
     * @param bool $headless true → {@code headless/} (59222); false → {@code headed/} (59223)
     */
    public static function defaultUserDataDir(bool $headless = true): string
    {
        $mode = $headless ? 'headless' : 'headed';

        return self::chromeRoot() . self::sep() . $mode;
    }

    /** Default debugging port for a mode profile. */
    public static function defaultDebuggingPort(bool $headless = true): int
    {
        return $headless ? self::PORT_HEADLESS : self::PORT_HEADED;
    }

    /**
     * One-click CDP launcher shipped in this package (`bin/`).
     *
     * Role: the path to name when a closed port needs a human to start Chrome.
     * {@code start-cdp.sh} on Unix, {@code start-cdp.ps1} on Windows.
     */
    public static function packageStartScript(): string
    {
        return dirname(__DIR__) . '/bin/' . self::startScriptName();
    }

    /**
     * One-click CDP launcher path inside the **headed** profile.
     *
     * Role: human entry — start headed Chrome on `PORT_HEADED` if down.
     */
    public static function startScriptPath(?string $userDataDir = null): string
    {
        $dir = $userDataDir !== null && $userDataDir !== ''
            ? $userDataDir
            : self::defaultUserDataDir(false);

        return $dir . self::sep() . self::startScriptName();
    }

    /**
     * Materialize `startScriptPath()` from the package template (idempotent).
     *
     * Defaults to the headed profile directory.
     *
     * @return string Absolute path to the script (empty on failure).
     */
    public function ensureStartScript(?string $userDataDir = null): string
    {
        $dir = $userDataDir !== null && $userDataDir !== ''
            ? $userDataDir
            : self::defaultUserDataDir(false);
        if (!$this->filesystem->isDir($dir)) {
            try {
                $this->filesystem->mkdir($dir, 0700);
            } catch (CreateDirectoryFailedException) {
                return '';
            }
        }

        $dest = self::startScriptPath($dir);
        $template = self::packageStartScript();
        if (!$this->filesystem->isFile($template)) {
            return '';
        }

        try {
            $body = $this->filesystem->read($template);
        } catch (FileNotFoundException) {
            return '';
        }

        $current = null;
        if ($this->filesystem->isFile($dest)) {
            try {
                $current = $this->filesystem->read($dest);
            } catch (FileNotFoundException) {
                $current = null;
            }
        }
        if ($current !== $body) {
            try {
                $this->filesystem->write($dest, $body);
            } catch (RuntimeException) {
            }
        }
        if (!self::isWindows()) {
            chmod($dest, 0755);
        }

        return $dest;
    }

    /** Daily Chrome profile root (CDP cannot bind here on modern Chrome). */
    public static function systemUserDataDir(): string
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $home = getenv('HOME') ?: '';

            return $home === ''
                ? ''
                : $home . '/Library/Application Support/Google/Chrome';
        }

        if (self::isWindows()) {
            $local = getenv('LOCALAPPDATA') ?: '';

            return $local === ''
                ? ''
                : $local . '\\Google\\Chrome\\User Data';
        }

        $home = getenv('HOME') ?: '';
        if ($home === '') {
            return '';
        }

        foreach ([
            $home . '/.config/google-chrome',
            $home . '/.config/chromium',
        ] as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return $home . '/.config/google-chrome';
    }

    protected static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /** Launch-script file name for the current OS. */
    protected static function startScriptName(): string
    {
        return self::isWindows() ? 'start-cdp.ps1' : 'start-cdp.sh';
    }

    protected static function homeDir(): string
    {
        if (self::isWindows()) {
            return (string) (getenv('USERPROFILE') ?: (getenv('HOME') ?: ''));
        }

        return (string) (getenv('HOME') ?: '');
    }

    protected static function sep(): string
    {
        return self::isWindows() ? '\\' : '/';
    }

    protected static function usable(string $path): bool
    {
        return file_exists($path) && (is_executable($path) || self::isWindows());
    }
}
