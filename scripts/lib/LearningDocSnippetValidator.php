<?php

declare(strict_types=1);

namespace Switon\Scripts\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;

final class LearningDocSnippetValidator
{
    private readonly string $autoload;

    private readonly string $tmpDir;

    private bool $bootstrapped = false;

    public function __construct(
        private readonly string $root,
        ?string $tmpDir = null,
    ) {
        $this->autoload = $this->root . '/vendor/autoload.php';
        $this->tmpDir = $tmpDir ?? sprintf(
            '%s/build/tmp-learning-doc-snippets-%d-%s',
            $this->root,
            getmypid(),
            bin2hex(random_bytes(4)),
        );
    }

    public function logicalTargetNames(): array
    {
        return ['tutorial', 'guides', 'getting-started'];
    }

    public function bootstrap(): void
    {
        if ($this->bootstrapped) {
            return;
        }

        if (!is_file($this->autoload)) {
            throw new RuntimeException("Composer autoload not found: {$this->autoload}");
        }

        require_once $this->autoload;

        $psr4Maps = $this->buildPsr4Maps();
        $this->registerPsr4Autoloader($psr4Maps);
        $this->bootstrapped = true;
    }

    public function resolveTargets(array $args): array
    {
        $logicalTargets = $this->logicalTargets();

        if ($args === []) {
            $args = ['learning-docs'];
        }

        $targets = [];
        foreach ($args as $arg) {
            $arg = trim($arg);
            if ($arg === '') {
                continue;
            }

            if ($arg === 'learning-docs') {
                foreach ($logicalTargets as $label => $path) {
                    $targets[$path] = ['label' => $label, 'path' => $path];
                }
                continue;
            }

            if (isset($logicalTargets[$arg])) {
                $path = $logicalTargets[$arg];
                $targets[$path] = ['label' => $arg, 'path' => $path];
                continue;
            }

            $candidate = $arg;
            if (!str_starts_with($candidate, '/')) {
                $candidate = $this->root . '/' . ltrim($candidate, '/');
            }

            $real = realpath($candidate);
            if ($real === false) {
                $supportedTargets = implode(', ', array_merge($this->logicalTargetNames(), ['learning-docs']));
                throw new RuntimeException(
                    "Target not found: {$arg}\nSupported logical targets: {$supportedTargets}"
                );
            }

            $targets[$real] = [
                'label' => $this->relativePath($real),
                'path' => $real,
            ];
        }

        return array_values($targets);
    }

    public function validateTargets(array $targets): array
    {
        $this->bootstrap();
        $this->ensureTmpDir();

        $files = $this->collectTargetFiles($targets);
        [$snippets, $knownIllustrativeTypes] = $this->collectSnippets($files);

        $syntaxFailures = [];
        $symbolFailures = [];
        $methodFailures = [];
        $okSnippets = [];

        try {
            foreach ($snippets as $snippet) {
                $tmpFile = $this->createTmpFile($snippet);
                $syntaxFailure = $this->lintSnippet($snippet['id'], $tmpFile);
                if ($syntaxFailure !== null) {
                    $syntaxFailures[] = $syntaxFailure;
                    continue;
                }

                $missingSymbols = $this->collectMissingSymbols($snippet['code'], $snippet['context']);
                if ($missingSymbols !== []) {
                    $symbolFailures[] = sprintf('%s: %s', $snippet['id'], implode(', ', $missingSymbols));
                    continue;
                }

                $missingMethods = $this->collectMissingMethods(
                    $snippet['code'],
                    $snippet['context'],
                    $knownIllustrativeTypes,
                );
                if ($missingMethods !== []) {
                    $methodFailures[] = sprintf('%s: %s', $snippet['id'], implode('; ', $missingMethods));
                    continue;
                }

                $okSnippets[] = $snippet['id'];
            }
        } finally {
            $this->cleanupTmpDir();
        }

        return [
            'targets' => $targets,
            'files' => $files,
            'totalSnippets' => count($snippets),
            'okSnippets' => $okSnippets,
            'syntaxFailures' => $syntaxFailures,
            'symbolFailures' => $symbolFailures,
            'methodFailures' => $methodFailures,
        ];
    }

    public function printFailures(string $title, array $failures): void
    {
        if ($failures === []) {
            return;
        }

        echo "\n[{$title}]\n";
        foreach ($failures as $failure) {
            echo '- ' . $failure . "\n";
        }
    }

    private function logicalTargets(): array
    {
        return [
            'tutorial' => $this->root . '/docs/en/tutorial',
            'guides' => $this->root . '/docs/en/guides',
            'getting-started' => $this->root . '/docs/en/getting-started',
        ];
    }

    private function collectTargetFiles(array $targets): array
    {
        $files = [];
        foreach ($targets as $target) {
            foreach ($this->collectMarkdownFiles($target['path']) as $file) {
                $files[$file] = true;
            }
        }

        $files = array_keys($files);
        sort($files);

        return $files;
    }

    private function collectSnippets(array $files): array
    {
        $snippets = [];
        $knownIllustrativeTypes = [];

        foreach ($files as $file) {
            $markdown = file_get_contents($file);
            if ($markdown === false) {
                continue;
            }

            if (!preg_match_all('/```php\s*\n(.*?)\n```/s', $markdown, $matches)) {
                continue;
            }

            foreach ($matches[1] as $index => $code) {
                $context = $this->buildContext($code);
                $id = $this->relativePath($file) . ' snippet ' . ($index + 1);
                $snippets[] = [
                    'id' => $id,
                    'file' => $file,
                    'index' => $index + 1,
                    'code' => $code,
                    'context' => $context,
                ];

                foreach ($context['definedTypes'] as $fqcn => $meta) {
                    $knownIllustrativeTypes[$fqcn] = $meta;
                }
            }
        }

        return [$snippets, $knownIllustrativeTypes];
    }

    private function ensureTmpDir(): void
    {
        if (!is_dir($this->tmpDir) && !mkdir($this->tmpDir, 0777, true) && !is_dir($this->tmpDir)) {
            throw new RuntimeException("Cannot create temp directory: {$this->tmpDir}");
        }
    }

    private function cleanupTmpDir(): void
    {
        foreach (glob($this->tmpDir . '/*.php') ?: [] as $tmpFile) {
            @unlink($tmpFile);
        }

        @rmdir($this->tmpDir);
    }

    private function createTmpFile(array $snippet): string
    {
        $tmpFile = sprintf(
            '%s/%s-snippet-%02d.php',
            $this->tmpDir,
            substr(sha1($snippet['file']), 0, 12),
            $snippet['index'],
        );
        $wrapped = str_starts_with(ltrim($snippet['code']), '<?php')
            ? $snippet['code']
            : "<?php\n" . $snippet['code'];
        file_put_contents($tmpFile, $wrapped);

        return $tmpFile;
    }

    private function lintSnippet(string $snippetId, string $tmpFile): ?string
    {
        $lintOutput = [];
        $lintExit = 0;
        exec('php -l ' . escapeshellarg($tmpFile) . ' 2>&1', $lintOutput, $lintExit);
        if ($lintExit === 0) {
            return null;
        }

        return sprintf('%s: %s', $snippetId, trim(implode("\n", $lintOutput)));
    }

    private function collectMissingSymbols(string $code, array $context): array
    {
        $missingSymbols = [];

        foreach ($this->extractImportedSymbols($code) as $symbol) {
            $resolved = $this->resolveTypeName($symbol, $context);
            if ($resolved === null || !$this->shouldCheckSymbol($resolved)) {
                continue;
            }

            if (!$this->symbolExists($resolved)) {
                $missingSymbols[] = $resolved;
            }
        }

        foreach ($this->extractFullyQualifiedSymbols($code) as $symbol) {
            if (!$this->shouldCheckSymbol($symbol)) {
                continue;
            }

            if (!$this->symbolExists($symbol)) {
                $missingSymbols[] = $symbol;
            }
        }

        return array_values(array_unique($missingSymbols));
    }

    private function collectMissingMethods(string $code, array $context, array $knownIllustrativeTypes): array
    {
        $missingMethods = [];
        $variableTypes = $this->extractVariableTypes($code, $context);

        foreach ($this->extractMethodCalls($code) as [$variable, $method]) {
            $type = $variableTypes[$variable] ?? null;
            if ($type === null) {
                continue;
            }

            $reflectionTarget = $this->resolveReflectionTarget($type, $knownIllustrativeTypes);
            if ($reflectionTarget === null) {
                continue;
            }

            $reflection = new ReflectionClass($reflectionTarget);
            if (!$reflection->hasMethod($method) && !$this->reflectionSupportsDynamicMethods($reflection)) {
                $missingMethods[] = sprintf('$%s->%s() on %s', $variable, $method, $reflectionTarget);
            }
        }

        return array_values(array_unique($missingMethods));
    }

    private function buildPsr4Maps(): array
    {
        $maps = [];

        $this->appendComposerPsr4Maps($this->root . '/composer.json', $this->root, $maps);

        foreach (glob($this->root . '/packages/*/composer.json') ?: [] as $composerFile) {
            $this->appendComposerPsr4Maps($composerFile, dirname($composerFile), $maps);
        }

        foreach ($maps as $prefix => $paths) {
            $maps[$prefix] = array_values(array_unique($paths));
        }

        uksort($maps, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return $maps;
    }

    private function appendComposerPsr4Maps(string $composerFile, string $baseDir, array &$maps): void
    {
        if (!is_file($composerFile)) {
            return;
        }

        $json = json_decode((string) file_get_contents($composerFile), true);
        if (!is_array($json)) {
            return;
        }

        $psr4 = $json['autoload']['psr-4'] ?? [];
        if (!is_array($psr4)) {
            return;
        }

        foreach ($psr4 as $prefix => $paths) {
            foreach ((array) $paths as $path) {
                $fullPath = $baseDir . '/' . ltrim((string) $path, '/');
                $resolved = realpath($fullPath) ?: $fullPath;
                if (!is_dir($resolved)) {
                    continue;
                }

                $maps[$prefix][] = rtrim(str_replace('\\', '/', $resolved), '/');
            }
        }
    }

    private function registerPsr4Autoloader(array $psr4Maps): void
    {
        spl_autoload_register(static function (string $class) use ($psr4Maps): void {
            foreach ($psr4Maps as $prefix => $paths) {
                if (!str_starts_with($class, $prefix)) {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                foreach ($paths as $path) {
                    $candidate = $path . '/' . $relative;
                    if (is_file($candidate)) {
                        require_once $candidate;
                        return;
                    }
                }
            }
        });
    }

    private function collectMarkdownFiles(string $target): array
    {
        if (is_file($target)) {
            return str_ends_with($target, '.md') ? [$target] : [];
        }

        if (!is_dir($target)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            if (str_ends_with($path, '.md')) {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    private function relativePath(string $path): string
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $this->root), '/');
        $normalizedPath = str_replace('\\', '/', $path);

        if (str_starts_with($normalizedPath, $normalizedRoot . '/')) {
            return substr($normalizedPath, strlen($normalizedRoot) + 1);
        }

        return $normalizedPath;
    }

    private function buildContext(string $code): array
    {
        $namespace = '';
        if (preg_match('/^namespace\s+([^;]+);/m', $code, $match)) {
            $namespace = trim($match[1]);
        }

        $imports = [];
        foreach ($this->extractImportedSymbols($code) as $importLine) {
            $parts = preg_split('/\s+as\s+/i', $importLine);
            $fqcn = trim($parts[0]);
            $alias = trim($parts[1] ?? basename(str_replace('\\', '/', $fqcn)));
            $imports[$alias] = ltrim($fqcn, '\\');
        }

        $definedTypes = [];
        if (
            preg_match_all(
                '/\b(class|interface|trait)\s+([A-Z][A-Za-z0-9_]*)\s*(?:extends\s+([^\s\{]+))?\s*(?:implements\s+([^\{]+))?/m',
                $code,
                $matches,
                PREG_SET_ORDER,
            )
        ) {
            foreach ($matches as $match) {
                $name = $match[2];
                $fqcn = ltrim(($namespace !== '' ? $namespace . '\\' : '') . $name, '\\');
                $extends = $this->resolveTypeName(
                    trim($match[3] ?? ''),
                    ['namespace' => $namespace, 'imports' => $imports],
                );
                $implements = [];
                if (!empty($match[4])) {
                    foreach (explode(',', $match[4]) as $segment) {
                        $resolved = $this->resolveTypeName(
                            trim($segment),
                            ['namespace' => $namespace, 'imports' => $imports],
                        );
                        if ($resolved !== null) {
                            $implements[] = $resolved;
                        }
                    }
                }

                $definedTypes[$fqcn] = [
                    'extends' => $extends,
                    'implements' => $implements,
                ];
            }
        }

        return [
            'namespace' => $namespace,
            'imports' => $imports,
            'definedTypes' => $definedTypes,
        ];
    }

    private function extractImportedSymbols(string $code): array
    {
        preg_match_all('/^use\s+([^;]+);/mi', $code, $matches);
        $symbols = [];

        foreach ($matches[1] ?? [] as $importLine) {
            foreach (explode(',', $importLine) as $segment) {
                $segment = trim($segment);
                if ($segment !== '') {
                    $symbols[] = $segment;
                }
            }
        }

        return $symbols;
    }

    private function extractFullyQualifiedSymbols(string $code): array
    {
        preg_match_all('/\\\\[A-Z][A-Za-z0-9_\\\\]*/', $code, $matches);

        return array_map(static fn (string $symbol): string => ltrim($symbol, '\\'), $matches[0] ?? []);
    }

    private function extractVariableTypes(string $code, array $context): array
    {
        $types = [];

        if (preg_match_all('/\$this->([A-Za-z_][A-Za-z0-9_]*)->/', $code, $propertyCalls)) {
            foreach ($propertyCalls[1] as $property) {
                $pattern = '/(?:public|protected|private)\s+(?:readonly\s+)?(?:\??)([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s+\$'
                    . preg_quote($property, '/')
                    . '\b/m';
                if (preg_match($pattern, $code, $match)) {
                    $resolved = $this->resolveTypeName($match[1], $context);
                    if ($resolved !== null) {
                        $types['this->' . $property] = $resolved;
                    }
                }
            }
        }

        if (preg_match_all('/function\s+[A-Za-z_][A-Za-z0-9_]*\s*\((.*?)\)/s', $code, $functions)) {
            foreach ($functions[1] as $parameterList) {
                if (
                    !preg_match_all(
                        '/(?:^|,)\s*(?:readonly\s+)?(?:\??)([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s+\$([A-Za-z_][A-Za-z0-9_]*)/',
                        $parameterList,
                        $params,
                        PREG_SET_ORDER,
                    )
                ) {
                    continue;
                }

                foreach ($params as $param) {
                    if (!isset($param[1], $param[2])) {
                        continue;
                    }

                    $resolved = $this->resolveTypeName($param[1], $context);
                    if ($resolved !== null) {
                        $types[$param[2]] = $resolved;
                    }
                }
            }
        }

        return $types;
    }

    private function extractMethodCalls(string $code): array
    {
        $calls = [];

        if (
            preg_match_all(
                '/\$this->([A-Za-z_][A-Za-z0-9_]*)->([A-Za-z_][A-Za-z0-9_]*)\s*\(/',
                $code,
                $matches,
                PREG_SET_ORDER,
            )
        ) {
            foreach ($matches as $match) {
                $calls[] = ['this->' . $match[1], $match[2]];
            }
        }

        if (
            preg_match_all(
                '/\$([A-Za-z_][A-Za-z0-9_]*)->([A-Za-z_][A-Za-z0-9_]*)\s*\(/',
                $code,
                $matches,
                PREG_SET_ORDER,
            )
        ) {
            foreach ($matches as $match) {
                if ($match[1] === 'this') {
                    continue;
                }

                $calls[] = [$match[1], $match[2]];
            }
        }

        return $calls;
    }

    private function resolveTypeName(string $type, array $context): ?string
    {
        $type = trim($type);
        if ($type === '') {
            return null;
        }

        $type = ltrim($type, '?');
        $type = trim($type, "\\\\ ");

        if (
            $type === ''
            || in_array(
                strtolower($type),
                ['array', 'string', 'int', 'float', 'bool', 'mixed', 'void', 'callable', 'iterable', 'object', 'self', 'static', 'parent'],
                true,
            )
        ) {
            return null;
        }

        if (str_contains($type, '\\')) {
            return ltrim($type, '\\');
        }

        if (isset($context['imports'][$type])) {
            return $context['imports'][$type];
        }

        if (!empty($context['namespace'])) {
            return $context['namespace'] . '\\' . $type;
        }

        return $type;
    }

    private function shouldCheckSymbol(string $symbol): bool
    {
        return str_starts_with($symbol, 'Switon\\')
            || str_starts_with($symbol, 'Psr\\')
            || $symbol === 'Throwable'
            || $symbol === 'stdClass';
    }

    private function symbolExists(string $symbol): bool
    {
        return class_exists($symbol)
            || interface_exists($symbol)
            || trait_exists($symbol)
            || (function_exists('enum_exists') && enum_exists($symbol));
    }

    private function resolveReflectionTarget(string $type, array $knownIllustrativeTypes): ?string
    {
        if ($this->symbolExists($type)) {
            return $type;
        }

        $meta = $knownIllustrativeTypes[$type] ?? null;
        if ($meta === null) {
            return null;
        }

        if (!empty($meta['extends']) && $this->symbolExists($meta['extends'])) {
            return $meta['extends'];
        }

        foreach ($meta['implements'] as $interface) {
            if ($this->symbolExists($interface)) {
                return $interface;
            }
        }

        return null;
    }

    private function reflectionSupportsDynamicMethods(ReflectionClass $reflection): bool
    {
        if ($reflection->hasMethod('__call')) {
            return true;
        }

        $docComment = $reflection->getDocComment() ?: '';
        if ($docComment !== '' && preg_match('/@mixin\s+[^\s]+/', $docComment) === 1) {
            return true;
        }

        foreach ($reflection->getInterfaceNames() as $interfaceName) {
            if ($interfaceName === $reflection->getName()) {
                continue;
            }

            $interface = new ReflectionClass($interfaceName);
            if ($this->reflectionSupportsDynamicMethods($interface)) {
                return true;
            }
        }

        $parent = $reflection->getParentClass();

        return $parent instanceof ReflectionClass && $this->reflectionSupportsDynamicMethods($parent);
    }
}
