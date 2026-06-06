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
use Switon\Core\Json;

use function file_get_contents;
use function file_put_contents;
use function getcwd;
use function implode;
use function is_dir;
use function is_file;
use function is_readable;
use function json_decode;
use function token_get_all;
use function trim;

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
            $out = [];
            exec('php -l ' . escapeshellarg($file) . ' 2>&1', $out);
            $line = implode(' ', $out);
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
        $cwd = getcwd();
        if (isset($p[0]) && $p[0] === '/') {
            return $p;
        }
        return $cwd . '/' . $p;
    }

    /** @return list<string> */
    protected function collectPhpFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
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
        if (!is_file($cacheFile)) {
            return false;
        }
        $installed = $cwd . '/vendor/composer/installed.json';
        return !(is_file($installed) && filemtime($installed) > filemtime($cacheFile));
    }

    /** @return list<string> */
    protected function getVendorDevPackageNames(string $cwd): array
    {
        $installed = $cwd . '/vendor/composer/installed.json';
        if (!is_file($installed)) {
            return [];
        }
        $data = json_decode((string)(file_get_contents($installed) ?: ''), true);
        return $data['dev-package-names'] ?? [];
    }

    /** @return list<array{class: string, file: string, summary: string, methods: list<array{name: string, summary: string}>}> */
    protected function loadOrBuildVendorCache(string $cwd, bool $includeDev): array
    {
        if ($this->isVendorCacheValid($cwd)) {
            $raw = (string)(file_get_contents($this->getVendorCachePath($cwd)) ?: '');
            $data = json_decode($raw, true);
            if (isset($data['classes']) && is_array($data['classes']) && array_is_list($data['classes'])) {
                /** @var list<array{class: string, file: string, summary: string, methods: list<array{name: string, summary: string}>}> $classes */
                $classes = $data['classes'];
                return $classes;
            }
        }
        $vendorDir = $cwd . '/vendor';
        if (!is_dir($vendorDir)) {
            return [];
        }
        $classes = $this->scanVendorForClassSummaries($vendorDir, $includeDev ? [] : $this->getVendorDevPackageNames($cwd));
        $cacheFile = $this->getVendorCachePath($cwd);
        $dir = dirname($cacheFile);
        if (is_dir($dir) || mkdir($dir, 0755, true) || is_dir($dir)) {
            file_put_contents($cacheFile, Json::stringify(['classes' => $classes], JSON_PRETTY_PRINT));
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
        if (!is_dir($vendorDir)) {
            return [];
        }
        if (!is_readable($vendorDir)) {
            return [];
        }

        $dirs = scandir($vendorDir);
        if ($dirs === false) {
            return [];
        }
        foreach ($dirs as $v) {
            if ($v === '.' || $v === '..' || $v === 'composer') {
                continue;
            }
            $vendorPath = $vendorDir . '/' . $v;
            if (!is_dir($vendorPath)) {
                continue;
            }
            if (!is_readable($vendorPath)) {
                continue;
            }

            $subs = scandir($vendorPath);
            if ($subs === false) {
                continue;
            }
            foreach ($subs as $pkg) {
                if ($pkg === '.' || $pkg === '..') {
                    continue;
                }
                $pkgName = $v . '/' . $pkg;
                if (isset($devSet[$pkgName])) {
                    continue;
                }
                $pkgPath = $vendorPath . '/' . $pkg;
                if (is_dir($pkgPath)) {
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
            if (!is_readable($file)) {
                continue;
            }

            $code = file_get_contents($file);
            if ($code === false) {
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
