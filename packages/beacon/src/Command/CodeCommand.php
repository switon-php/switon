<?php

declare(strict_types=1);

namespace Switon\Beacon\Command;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use Switon\Command\Attribute\Hidden;
use Switon\Command\Attribute\Tool;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;
use Switon\Core\Exception\FileNotFoundException;
use Switon\Core\Exception\RuntimeException;
use Switon\Core\FilesystemInterface;
use Switon\Core\Json;
use Switon\Core\Path;
use Switon\Core\Program;

use function getcwd;
use function implode;
use function json_decode;
use function str_contains;
use function str_replace;
use function token_get_all;
use function trim;

use const PHP_BINARY;
use const T_CLASS;
use const T_DOC_COMMENT;
use const T_ENUM;
use const T_FUNCTION;
use const T_NAMESPACE;
use const T_STRING;
use const T_WHITESPACE;

/**
 * Lint PHP files. class:list / class:inspect / class:content live in ClassCommand.
 *
 * @see \Switon\Beacon\Command\ClassCommand
 * @see \Switon\Cli\Handler
 */
#[Hidden]
class CodeCommand
{
    #[Autowired] protected ConsoleInterface $console;
    #[Autowired] protected FilesystemInterface $filesystem;

    protected const string DEFAULT_PATH = 'app';
    protected const string VENDOR_CACHE_FILE = 'vendor/switon/cli-code-index.json';

    /**
     * Run php -l on each PHP file under path (default app/). Always outputs JSON.
     *
     * @param string $path Directory to scan (default app)
     */
    #[Tool('[path]. Path = directory to scan; default app/. Returns JSON: ok, errors[] → file, message.')]
    public function lintAction(string $path = ''): int
    {
        $dir = $this->resolvePath($path);
        $files = $this->collectPhpFiles($dir);
        $errors = [];

        foreach ($files as $file) {
            $line = trim(Program::run([PHP_BINARY, '-l', $file], true)['output']);
            if (!str_contains($line, 'No syntax errors')) {
                $errors[] = ['file' => $file, 'message' => $line ?: 'syntax error'];
            }
        }

        $this->console->writeLn(Json::stringify([
            'ok' => empty($errors),
            'errors' => $errors,
        ]));
        return empty($errors) ? 0 : 1;
    }

    protected function resolvePath(string $path): string
    {
        $p = $path !== '' ? $path : self::DEFAULT_PATH;
        if (Path::isAbsolute($p)) {
            return $p;
        }
        $cwd = getcwd();
        return $cwd . '/' . $p;
    }

    /** @return list<string> */
    protected function collectPhpFiles(string $dir): array
    {
        if (!$this->filesystem->isDir($dir)) {
            return [];
        }
        $files = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', $f->getPathname());
            }
        }
        return $files;
    }

    protected function visibility(int $modifiers): string
    {
        if ($modifiers & ReflectionMethod::IS_PUBLIC) {
            return 'public';
        }
        if ($modifiers & ReflectionMethod::IS_PROTECTED) {
            return 'protected';
        }
        if ($modifiers & ReflectionMethod::IS_PRIVATE) {
            return 'private';
        }
        return 'public';
    }

    protected function getVendorCachePath(string $cwd): string
    {
        return $cwd . '/' . self::VENDOR_CACHE_FILE;
    }

    protected function isVendorCacheValid(string $cwd): bool
    {
        $cacheFile = $this->getVendorCachePath($cwd);
        if (!$this->filesystem->isFile($cacheFile)) {
            return false;
        }
        $installed = $cwd . '/vendor/composer/installed.json';
        if (!$this->filesystem->isFile($installed)) {
            return true;
        }

        return ($this->filesystem->mtime($installed) ?? 0) <= ($this->filesystem->mtime($cacheFile) ?? 0);
    }

    /** @return list<string> */
    protected function getVendorDevPackageNames(string $cwd): array
    {
        $installed = $cwd . '/vendor/composer/installed.json';
        if (!$this->filesystem->isFile($installed)) {
            return [];
        }
        try {
            $raw = $this->filesystem->read($installed);
        } catch (FileNotFoundException) {
            $raw = '';
        }
        $data = json_decode($raw !== '' ? $raw : '', true);
        return $data['dev-package-names'] ?? [];
    }

    /** @return list<array{class: string, file: string, summary: string, methods: list<array{name: string, summary: string}>}> */
    protected function loadOrBuildVendorCache(string $cwd, bool $includeDev): array
    {
        if ($this->isVendorCacheValid($cwd)) {
            try {
                $raw = $this->filesystem->read($this->getVendorCachePath($cwd));
            } catch (FileNotFoundException) {
                $raw = '';
            }
            $data = json_decode($raw !== '' ? $raw : '', true);
            if (isset($data['classes']) && is_array($data['classes']) && array_is_list($data['classes'])) {
                /** @var list<array{class: string, file: string, summary: string, methods: list<array{name: string, summary: string}>}> $classes */
                $classes = $data['classes'];
                return $classes;
            }
        }
        $vendorDir = $cwd . '/vendor';
        if (!$this->filesystem->isDir($vendorDir)) {
            return [];
        }
        $classes = $this->scanVendorForClassSummaries($vendorDir, $includeDev ? [] : $this->getVendorDevPackageNames($cwd));
        $cacheFile = $this->getVendorCachePath($cwd);
        try {
            $this->filesystem->write($cacheFile, Json::stringify(['classes' => $classes], JSON_PRETTY_PRINT));
        } catch (RuntimeException) {
            // Cache write is best-effort; a failed write used to be ignored.
        }
        return $classes;
    }

    /** @param list<string> $devPackageNames
     * @return list<array{class: string, file: string, summary: string, methods: list<array{name: string, summary: string}>}>
     */
    protected function scanVendorForClassSummaries(string $vendorDir, array $devPackageNames): array
    {
        $classes = [];
        $devSet = array_flip($devPackageNames);
        if (!$this->filesystem->isDir($vendorDir) || !$this->filesystem->isReadable($vendorDir)) {
            return [];
        }

        try {
            $dirs = $this->filesystem->list($vendorDir);
        } catch (RuntimeException) {
            return [];
        }
        foreach ($dirs as $v) {
            if ($v === 'composer') {
                continue;
            }
            $vendorPath = $vendorDir . '/' . $v;
            if (!$this->filesystem->isDir($vendorPath) || !$this->filesystem->isReadable($vendorPath)) {
                continue;
            }

            try {
                $subs = $this->filesystem->list($vendorPath);
            } catch (RuntimeException) {
                continue;
            }
            foreach ($subs as $pkg) {
                $pkgName = $v . '/' . $pkg;
                if (isset($devSet[$pkgName])) {
                    continue;
                }
                $pkgPath = $vendorPath . '/' . $pkg;
                if ($this->filesystem->isDir($pkgPath)) {
                    foreach ($this->scanPathForClassSummaries($pkgPath) as $c) {
                        $classes[] = $c;
                    }
                }
            }
        }
        return $classes;
    }

    /** @return list<array{class: string, file: string, summary: string, methods: list<array{name: string, summary: string}>}> */
    protected function scanPathForClassSummaries(string $dir): array
    {
        $files = $this->collectPhpFiles($dir);
        $classes = [];
        foreach ($files as $file) {
            if (!$this->filesystem->isReadable($file)) {
                continue;
            }

            try {
                $code = $this->filesystem->read($file);
            } catch (FileNotFoundException) {
                continue;
            }
            foreach ($this->extractClassSummariesFromSource($code, $file) as $c) {
                $classes[] = $c;
            }
        }
        return $classes;
    }

    /** @return list<array{class: string, file: string, summary: string, methods: list<array{name: string, summary: string}>}> */
    protected function extractClassSummariesFromSource(string $code, string $file): array
    {
        $tokens = token_get_all($code);
        $ns = '';
        $result = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t)) {
                continue;
            }
            if ($t[0] === T_NAMESPACE) {
                $i++;
                while ($i < $n && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                    $i++;
                }
                $parts = [];
                while ($i < $n) {
                    $cur = $tokens[$i];
                    if (is_array($cur) && $cur[0] === T_STRING) {
                        $parts[] = $cur[1];
                        $i++;
                    } elseif (is_array($cur) && $cur[0] === T_WHITESPACE) {
                        $i++;
                    } else {
                        break;
                    }
                }
                $ns = implode('\\', $parts);
                continue;
            }
            if ($t[0] === T_CLASS || $t[0] === T_ENUM) {
                $j = $i + 1;
                while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                if ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $name = $ns !== '' ? $ns . '\\' . $tokens[$j][1] : $tokens[$j][1];
                    $summary = '';
                    for ($k = $i - 1; $k >= 0; $k--) {
                        if (!is_array($tokens[$k])) {
                            continue;
                        }
                        if ($tokens[$k][0] === T_DOC_COMMENT) {
                            $summary = $this->extractSummaryFromDocblock($tokens[$k][1]);
                            break;
                        }
                        if ($tokens[$k][0] !== T_WHITESPACE) {
                            break;
                        }
                    }
                    $methods = [];
                    for ($k = $j + 1; $k < $n; $k++) {
                        $cur = $tokens[$k];
                        if (!is_array($cur)) {
                            continue;
                        }
                        if ($cur[0] === T_CLASS || $cur[0] === T_ENUM) {
                            break;
                        }
                        if ($cur[0] === T_FUNCTION) {
                            $idx = $k + 1;
                            while ($idx < $n && is_array($tokens[$idx]) && $tokens[$idx][0] === T_WHITESPACE) {
                                $idx++;
                            }
                            $mName = ($idx < $n && is_array($tokens[$idx]) && $tokens[$idx][0] === T_STRING) ? $tokens[$idx][1] : '';
                            $mSummary = '';
                            for ($b = $k - 1; $b >= 0; $b--) {
                                if (!is_array($tokens[$b])) {
                                    continue;
                                }
                                if ($tokens[$b][0] === T_DOC_COMMENT) {
                                    $mSummary = $this->extractSummaryFromDocblock($tokens[$b][1]);
                                    break;
                                }
                                if ($tokens[$b][0] !== T_WHITESPACE) {
                                    break;
                                }
                            }
                            if ($mName !== '') {
                                $methods[] = ['name' => $mName, 'summary' => $mSummary];
                            }
                        }
                    }
                    $result[] = ['class' => $name, 'file' => $file, 'summary' => $summary, 'methods' => $methods];
                }
            }
        }
        return $result;
    }

    protected function extractSummaryFromDocblock(string $doc): string
    {
        $lines = explode("\n", $doc);
        foreach ($lines as $line) {
            $line = trim($line, " \t*\r\n/");
            if ($line !== '' && $line !== '*') {
                return $line;
            }
        }
        return '';
    }
}
