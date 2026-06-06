<?php

declare(strict_types=1);

namespace Switon\Beacon\Command;

use Psr\Container\ContainerInterface;
use Switon\Command\Attribute\Hidden;
use Switon\Command\Attribute\Tool;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ClassName;
use Switon\Core\ConsoleInterface;
use Switon\Core\Json;
use Switon\Core\Naming;
use Switon\Di\ContainerInterface as DiContainerInterface;
use Switon\Di\FactoryInterface;
use Switon\Kernel\Env;
use Switon\Yaml\Exception\YamlParseException;
use Switon\Yaml\YamlReader;
use Traversable;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Throwable;

use function array_key_exists;
use function array_unique;
use function array_values;
use function basename;
use function class_exists;
use function dirname;
use function explode;
use function file_get_contents;
use function get_class;
use function get_debug_type;
use function glob;
use function implode;
use function interface_exists;
use function is_array;
use function is_dir;
use function is_file;
use function is_object;
use function is_readable;
use function is_scalar;
use function is_string;
use function iterator_to_array;
use function ltrim;
use function preg_match;
use function rtrim;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strrpos;
use function substr;

/**
 * Public `config:*` entrypoint: Autowired option listing plus `switon.yml` validation.
 *
 * Road-signs:
 * - Beacon owns the public `config:*` command surface
 * - `optionsAction` lists `#[Autowired]` config surface
 * - `checkAction` adds Beacon `switon.yml` diagnostics
 *
 * @see \Switon\Core\Attribute\Autowired
 * @see \Switon\Beacon\Command\ToolCommand::listAction()
 * @see \Switon\Command\CommandDiscoveryInterface
 */
#[Hidden]
class ConfigCommand
{
    #[Autowired] protected ConsoleInterface $console;
    #[Autowired] protected ContainerInterface $container;

    /**
     * List config keys (property names) and types for a class that receives config.
     *
     * Pass interface or implementation FQCN. For interface, container->get() returns the bound implementation.
     *
     * @param string $class Interface or implementation FQCN
     * @param bool $json Machine-readable output
     */
    #[Tool('<FQCN>. Returns JSON: class, config_file, options[] → name, type, instances, default.')]
    public function optionsAction(string $class, bool $json = true): int
    {
        $class = ClassName::normalize($class);

        $resolved = $this->resolveImplementation($class);
        if ($resolved === null) {
            if ($json) {
                $this->console->writeLn(Json::stringify(['error' => 'class not found', 'class' => $class]));
            } else {
                $this->console->writeLn('Class not found: ' . $class);
            }
            return 1;
        }

        $options = $this->collectAutowiredProperties($resolved, [$class, $resolved]);

        if ($json) {
            $payload = [
                'class' => $resolved,
                'config_file' => $this->configFileHint($class),
                'options' => $options,
            ];
            $this->console->writeLn(Json::stringify($payload));
            return 0;
        }

        $this->console->writeLn('Config options for ' . $resolved . ':');
        foreach ($options as $opt) {
            $line = '  ' . $opt['name'] . ': ' . $opt['type'];
            if ($opt['instances']) {
                $line .= ' (instances)';
            }
            if ($opt['default'] !== null) {
                $line .= ' = ' . (is_string($opt['default']) ? '"' . $opt['default'] . '"' : Json::stringify($opt['default']));
            } else {
                $line .= ' = null';
            }
            $this->console->writeLn($line);
        }
        return 0;
    }

    /**
     * Validate switon.yml (parse, interpolate, optional YAML vs PHP config type conflicts).
     *
     * This is a best-effort validator. By default it resolves interface bindings via container->get().
     *
     * @param string $file Path to switon.yml
     * @param string $configDir PHP config directory for conflict check; empty skips
     * @param bool $env Load .env files when true; default false (do not touch filesystem)
     * @param bool $instantiate Resolve interface bindings via container->get() when true (may have side effects)
     */
    #[Tool('[file] [configDir] [env] [instantiate]. Validate switon.yml. Returns JSON: ok(bool), file, errors[], warnings[].')]
    public function checkAction(
        string $file = 'switon.yml',
        string $configDir = 'config',
        bool   $env = false,
        bool   $instantiate = true
    ): int {
        $errors = [];
        $warnings = [];

        if (!is_file($file) || !is_readable($file)) {
            $this->console->writeLn(Json::stringify(['ok' => false, 'file' => $file, 'errors' => ["Failed to read: $file"]]));
            return 1;
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            $this->console->writeLn(Json::stringify(['ok' => false, 'file' => $file, 'errors' => ["Failed to read: $file"]]));
            return 1;
        }

        $yamlRoot = dirname($file);
        if ($yamlRoot === '.' || $yamlRoot === '') {
            $yamlRoot = getcwd() ?: '.';
        }

        if ($env) {
            (new Env("$yamlRoot/.env.local"))->load();
            (new Env("$yamlRoot/.env"))->load();
            (new Env('.env.local'))->load();
            (new Env('.env'))->load();
        }

        $reader = new YamlReader();

        try {
            /** @var array<string, mixed> $data */
            $data = $reader->parse($contents);
        } catch (YamlParseException $e) {
            $errors[] = 'YAML parse error: ' . $e->getMessage();
            $this->console->writeLn(Json::stringify(['ok' => false, 'file' => $file, 'errors' => $errors]));
            return 1;
        }

        try {
            /** @var array<string, mixed> $data */
            $data = $reader->interpolate($data, $this->buildCheckInterpolationVariables($data));
        } catch (YamlParseException $e) {
            // Keep going: allow detecting other high-signal issues (e.g. wrong FQCN service id)
            // even when env/parameters are incomplete.
            $errors[] = 'YAML interpolate error: ' . $e->getMessage();
        }

        foreach ($data as $id => $_) {
            if (!is_string($id) || !str_contains($id, '\\')) {
                continue;
            }
            if (class_exists($id) || interface_exists($id)) {
                continue;
            }
            // Best-effort: service ids may be aliases, late-loaded classes, or app-only classes in partial contexts.
            $warnings[] = "Service id looks like FQCN but class/interface not found: \"$id\"";
        }

        // Unknown config keys: mapping keys should match #[Autowired] properties (and constructor params) of the target class.
        foreach ($data as $id => $definition) {
            if (!is_string($id) || !is_array($definition)) {
                continue;
            }

            $class = null;
            if (isset($definition['class']) && is_string($definition['class']) && $definition['class'] !== '') {
                $class = $definition['class'];
            } elseif (class_exists($id)) {
                $class = $id;
            } elseif ($instantiate && interface_exists($id)) {
                $class = $this->resolveImplementation($id);
            }

            if ($class === null || !class_exists($class)) {
                continue;
            }

            // Factory mapping keys are named definitions; do not treat them as Autowired option keys.
            if (is_a($class, FactoryInterface::class, true)) {
                continue;
            }

            $knownKeys = $this->knownConfigKeysForClass($class);
            foreach ($definition as $key => $_value) {
                if (!is_string($key) || $key === '' || $key === 'class') {
                    continue;
                }
                if (!isset($knownKeys[$key])) {
                    // Best-effort: keys could be consumed by factories/adapters or dynamic mappers outside Autowired.
                    $warnings[] = "Unknown config key \"$key\" for \"$id\" (class: $class)";
                }
            }
        }

        if ($configDir !== '') {
            $dir = $configDir;
            if (!is_dir($dir)) {
                $dir = rtrim($yamlRoot, '/') . '/' . ltrim($configDir, '/');
            }
            if (is_dir($dir)) {
                $phpConfig = $this->loadPhpConfigForCheck($dir);
                foreach ($phpConfig as $id => $phpDef) {
                    if (!is_string($id) || !array_key_exists($id, $data)) {
                        continue;
                    }
                    $yamlDef = $data[$id];
                    if (is_array($yamlDef) && is_array($phpDef)) {
                        continue;
                    }
                    $errors[] = "YAML/PHP type conflict for \"$id\": YAML=" . get_debug_type($yamlDef) . ', PHP=' . get_debug_type($phpDef);
                }
            }
        }

        $this->console->writeLn(Json::stringify([
            'ok' => $errors === [],
            'file' => $file,
            'errors' => $errors,
            'warnings' => $warnings,
        ]));
        return $errors === [] ? 0 : 1;
    }

    /**
     * @param class-string $class
     *
     * @return array<string, true>
     */
    protected function knownConfigKeysForClass(string $class): array
    {
        if (!class_exists($class) && !interface_exists($class)) {
            return [];
        }

        $keys = [];

        $ref = new ReflectionClass($class);
        while ($ref !== false) {
            foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED) as $p) {
                $attrs = $p->getAttributes(Autowired::class);
                if ($attrs === []) {
                    continue;
                }
                $keys[$p->getName()] = true;
            }
            $ref = $ref->getParentClass() ?: false;
        }

        try {
            $ctor = (new ReflectionClass($class))->getConstructor();
        } catch (Throwable) {
            $ctor = null;
        }
        if ($ctor !== null) {
            foreach ($ctor->getParameters() as $param) {
                $name = $param->getName();
                if ($name !== '') {
                    $keys[$name] = true;
                }
            }
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $yaml
     *
     * @return array<string, string>
     */
    protected function buildCheckInterpolationVariables(array $yaml): array
    {
        $vars = [];
        $env = getenv();
        if (is_array($env)) {
            foreach ($env as $name => $value) {
                if (is_string($name) && is_string($value) && $name !== '') {
                    $vars[$name] = $value;
                }
            }
        }

        if (isset($yaml['parameters']) && is_array($yaml['parameters'])) {
            foreach ($yaml['parameters'] as $name => $value) {
                if (!is_string($name) || $name === '') {
                    continue;
                }
                if (is_scalar($value)) {
                    if (is_bool($value)) {
                        $vars[$name] = $value ? 'true' : 'false';
                    } else {
                        $vars[$name] = (string)$value;
                    }
                }
            }
        }

        return $vars;
    }

    /** @return array<string, mixed> */
    protected function loadPhpConfigForCheck(string $dir): array
    {
        $configs = [];
        foreach (glob(rtrim($dir, '/') . '/*.php') ?: [] as $path) {
            if (str_starts_with(basename($path), '.')) {
                continue;
            }
            $config = require $path;
            if (is_array($config)) {
                $configs += $config;
            }
        }

        return $configs;
    }

    /** Config file name by convention: short name without "Interface", kebab-case. */
    protected function configFileHint(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');
        $short = $pos !== false ? substr($fqcn, $pos + 1) : $fqcn;
        if (str_ends_with($short, 'Interface')) {
            $short = substr($short, 0, -9);
        }
        return 'config/' . Naming::kebab($short) . '.php';
    }

    protected function resolveImplementation(string $class): ?string
    {
        $class = ClassName::normalize($class);

        if (class_exists($class)) {
            return $class;
        }
        if (interface_exists($class)) {
            try {
                $instance = $this->container->get($class);
                if (!is_object($instance)) {
                    return null;
                }

                return $instance::class;
            } catch (Throwable $e) {
                if (preg_match('/^([A-Za-z0-9_\\\\]+)::/', $e->getMessage(), $m) === 1 && class_exists($m[1])) {
                    return $m[1];
                }
                return null;
            }
        }
        return null;
    }

    /**
     * @param class-string $className
     * @param list<string> $configIds Service IDs to inspect for user-provided config overrides.
     *
     * @return list<array{name: string, type: string, instances: bool, default: mixed}>
     */
    protected function collectAutowiredProperties(string $className, array $configIds = []): array
    {
        $options = [];
        $seen = [];
        $ref = new ReflectionClass($className);

        while ($ref !== false) {
            foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED) as $p) {
                if (isset($seen[$p->getName()])) {
                    continue;
                }
                $attrs = $p->getAttributes(Autowired::class);
                if ($attrs === []) {
                    continue;
                }
                $seen[$p->getName()] = true;
                $type = $p->getType();
                $typeStr = $type !== null ? $this->typeToString($type) : 'mixed';
                $instances = $attrs[0]->newInstance()->isInstances();
                $default = $p->hasDefaultValue() ? $p->getDefaultValue() : null;
                if ($instances) {
                    $typeStr = $this->describeInstancesType($p, $default, $configIds);
                }
                $options[] = [
                    'name' => $p->getName(),
                    'type' => $typeStr,
                    'instances' => $instances,
                    'default' => $default,
                ];
            }
            $ref = $ref->getParentClass() ?: false;
        }

        return $options;
    }

    /** @param list<string> $configIds */
    protected function describeInstancesType(ReflectionProperty $property, mixed $default, array $configIds): string
    {
        $entries = $this->resolveConfiguredInstancesEntries($property, $configIds);

        if ($entries === null) {
            if ($default instanceof Traversable) {
                $entries = iterator_to_array($default);
            } elseif (is_array($default)) {
                $entries = $default;
            } else {
                $entries = [];
            }
        }

        $types = [];
        foreach ($entries as $entry) {
            $type = $this->inferInstancesEntryType($entry);
            if ($type !== null) {
                $types[] = $type;
            }
        }

        $types = array_values(array_unique($types));
        if ($types === []) {
            return 'array';
        }

        sort($types);
        return 'array<' . implode('|', $types) . '>';
    }

    /** @param list<string> $configIds */
    /**
     * @param list<string> $configIds
     *
     * @return array<string, mixed>|null
     */
    protected function resolveConfiguredInstancesEntries(ReflectionProperty $property, array $configIds): ?array
    {
        if (!isset($this->container) || !$this->container instanceof DiContainerInterface) {
            return null;
        }

        $definitions = $this->container->getDefinitions();
        $name = $property->getName();

        foreach ($configIds as $id) {
            if ($id === '' || !array_key_exists($id, $definitions)) {
                continue;
            }

            $definition = $definitions[$id];
            if (!is_array($definition) || !array_key_exists($name, $definition)) {
                continue;
            }

            return $this->mergeConfiguredInstancesEntries($property, $definition[$name]);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function mergeConfiguredInstancesEntries(ReflectionProperty $property, mixed $configValue): ?array
    {
        if ($configValue instanceof Traversable) {
            return iterator_to_array($configValue);
        }

        if (!is_array($configValue)) {
            return null;
        }

        $configured = $this->filterNullEntries($configValue);
        $default = $property->hasDefaultValue() ? $property->getDefaultValue() : [];

        if ($default instanceof Traversable) {
            $default = iterator_to_array($default);
        }

        if (!is_array($default) || $default === []) {
            return $configured;
        }

        foreach ($default as $key => $_) {
            if (array_key_exists($key, $configValue) && $configValue[$key] === null) {
                unset($default[$key]);
            }
        }

        if ($default === []) {
            return $configured;
        }

        return $this->filterNullEntries([...$default, ...$configValue]);
    }

    /**
     * @param array<string, mixed> $entries
     *
     * @return array<string, mixed>
     */
    protected function filterNullEntries(array $entries): array
    {
        foreach ($entries as $key => $value) {
            if ($value === null) {
                unset($entries[$key]);
            }
        }

        return $entries;
    }

    protected function inferInstancesEntryType(mixed $entry): ?string
    {
        if (is_array($entry)) {
            $class = $entry['class'] ?? null;
            return is_string($class) && $class !== '' ? ClassName::normalize($class) : null;
        }

        if (is_object($entry)) {
            return get_class($entry);
        }

        if (!is_string($entry) || $entry === '') {
            return null;
        }

        return $this->inferServiceType($entry);
    }

    /** @param array<string, true> $seen */
    protected function inferServiceType(string $id, array $seen = []): string
    {
        $id = ClassName::normalize($id);

        if ($id === '') {
            return 'object';
        }

        if (isset($seen[$id])) {
            return 'object';
        }
        $seen[$id] = true;

        if ($this->container instanceof DiContainerInterface) {
            $definitions = $this->container->getDefinitions();
            if (array_key_exists($id, $definitions)) {
                $definition = $definitions[$id];

                if (is_object($definition)) {
                    return get_class($definition);
                }

                if (is_array($definition)) {
                    if (isset($definition['class']) && is_string($definition['class']) && $definition['class'] !== '') {
                        return ClassName::normalize($definition['class']);
                    }

                    if (class_exists($id) || interface_exists($id)) {
                        return $id;
                    }
                }

                if (is_string($definition) && $definition !== '') {
                    if (str_starts_with($definition, '#')) {
                        $target = str_contains($id, '#')
                            ? explode('#', $id, 2)[0] . $definition
                            : $id . $definition;
                        return $this->inferServiceType($target, $seen);
                    }

                    return $this->inferServiceType($definition, $seen);
                }
            }
        }

        if (str_contains($id, '#')) {
            $base = explode('#', $id, 2)[0];
            if ($base !== '' && (class_exists($base) || interface_exists($base))) {
                return $base;
            }
        }

        if (class_exists($id) || interface_exists($id)) {
            return $id;
        }

        return 'object';
    }

    protected function typeToString(ReflectionType $type): string
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->getName();
        }
        if ($type instanceof ReflectionUnionType) {
            $parts = [];
            foreach ($type->getTypes() as $t) {
                $parts[] = $t->getName();
            }
            return implode('|', $parts);
        }
        return 'mixed';
    }

}
