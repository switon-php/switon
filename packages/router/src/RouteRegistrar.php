<?php

declare(strict_types=1);

namespace Switon\Routing;

use Switon\Core\Attribute\Autowired;
use Switon\Core\Exception\JsonException;
use Switon\Core\FilesystemInterface;
use Switon\Core\Json;

use function dirname;
use function is_array;
use function is_string;

/**
 * Registers route definitions discovered from controller mapping attributes into the router.
 *
 * Use when bootstrapping attribute-based routing from configured controller file patterns.
 *
 * Guidance: Use {@see MappingDiscoveryInterface} for route discovery; this class owns cache IO and router registration only.
 * When `cache` is true, if `file` exists and is valid JSON it is loaded as-is; delete or replace that file to refresh routes.
 *
 * Road-signs:
 * - files patterns
 * - MappingDiscoveryInterface resolves route definitions
 * - register/registerClass write routes into RouterInterface
 * - register <code>router->add()</code>
 * - optional JSON cache <code>file</code> + <code>cache</code>
 *
 * @see \Switon\Routing\RouteRegistrarInterface
 * @see \Switon\Routing\MappingDiscoveryInterface
 * @see \Switon\Routing\RouterInterface
 * @see \Switon\Core\FilesystemInterface
 */
class RouteRegistrar implements RouteRegistrarInterface
{
    protected const int CACHE_VERSION = 1;

    #[Autowired] protected RouterInterface $router;
    #[Autowired] protected MappingDiscoveryInterface $mappingDiscovery;
    #[Autowired] protected FilesystemInterface $filesystem;

    /**
     * When true, load routes from `file` if it exists (no freshness check); otherwise scan and then write `file`.
     */
    #[Autowired] protected bool $cache = false;

    /**
     * Route manifest path (JSON). Used only when `cache` is true.
     *
     * @see \Switon\Routing\RouteRegistrar::$cache
     */
    #[Autowired] protected string $file = '@runtime/cache/routes.json';

    /**
     * Controller discovery glob patterns.
     *
     * @var array<string, string>
     *
     * @see \Switon\Core\Attribute\Autowired
     * @see \Switon\Kernel\Kernel
     */
    #[Autowired] protected array $files
        = ['@app/Controller/*Controller.php' => 'App\\Controller\\*Controller',
            '@app/Areas/*/Controller/*Controller.php' => 'App\\Areas\\*\\Controller\\*Controller'
        ];

    public function register(): void
    {
        if ($this->cache && $this->tryLoadFromCache()) {
            return;
        }

        $this->registerRoutes($this->mappingDiscovery->discover($this->files));

        if ($this->cache) {
            $this->writeCache();
        }
    }

    /**
     * Populates the router from the JSON manifest when the file exists and decodes to a valid payload.
     */
    protected function tryLoadFromCache(): bool
    {
        if (!$this->filesystem->exists($this->file)) {
            return false;
        }

        try {
            $raw = $this->filesystem->read($this->file);
            $data = Json::parse($raw);
        } catch (JsonException) {
            return false;
        }

        if (!is_array($data)
            || ($data['version'] ?? null) !== self::CACHE_VERSION
            || !isset($data['routes'])
            || !is_array($data['routes'])
        ) {
            return false;
        }

        $normalized = [];
        foreach ($data['routes'] as $row) {
            if (!is_array($row)) {
                return false;
            }
            $verb = $row['verb'] ?? null;
            $pattern = $row['pattern'] ?? null;
            $handler = $row['handler'] ?? null;
            if (!is_string($verb) || !is_string($pattern) || !is_string($handler)) {
                return false;
            }
            $normalized[] = [$verb, $pattern, $handler];
        }

        foreach ($normalized as [$verb, $pattern, $handler]) {
            $this->router->add($verb, $pattern, $handler);
        }

        return true;
    }

    /**
     * Writes the current router table to the JSON manifest.
     */
    protected function writeCache(): void
    {
        $dir = dirname($this->file);
        if (!$this->filesystem->isDir($dir)) {
            $this->filesystem->mkdir($dir);
        }

        $payload = [
            'version' => self::CACHE_VERSION,
            'routes' => $this->router->getAllRoutes(),
        ];
        $this->filesystem->write($this->file, Json::stringify($payload));
    }

    public function registerClass(string $className): void
    {
        $this->registerRoutes($this->mappingDiscovery->discoverClass($className));
    }

    /**
     * @param list<array{verb: string, pattern: string, handler: string}> $routes
     */
    protected function registerRoutes(array $routes): void
    {
        foreach ($routes as $route) {
            $this->router->add($route['verb'], $route['pattern'], $route['handler']);
        }
    }
}
