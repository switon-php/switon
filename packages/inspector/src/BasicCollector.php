<?php

declare(strict_types=1);

namespace Switon\Inspector;

use JsonSerializable;
use Switon\Core\AppInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\CollectorInterface;
use Switon\Http\RequestInterface;
use Switon\Kernel\VersionInterface;

use function date;
use function get_loaded_extensions;
use function implode;
use function ini_get;
use function memory_get_peak_usage;
use function memory_get_usage;
use function php_ini_loaded_file;
use function sort;

/**
 * Collects baseline request and runtime diagnostics for inspector pages.
 *
 * Use when inspector output should include request, server, and PHP environment data.
 *
 * @see \Switon\Core\CollectorInterface
 * @see \Switon\Inspector\CollectorDiscoveryInterface
 */
class BasicCollector implements CollectorInterface, JsonSerializable
{
    #[Autowired] protected AppInterface $app;
    #[Autowired] protected VersionInterface $frameworkVersion;
    #[Autowired] protected RequestInterface $request;

    /** {@inheritDoc} */
    public function boot(): void
    {
        // No initialization needed
    }

    /** @return array<string, mixed> */
    public function collect(): array
    {
        $loaded_extensions = get_loaded_extensions();
        sort($loaded_extensions, SORT_STRING | SORT_FLAG_CASE);
        $memory_usage = (int)(memory_get_usage(true) / 1024) . 'k/' . (int)(memory_get_peak_usage(true) / 1024) . 'k';

        return [
            'request' => [
                'handler' => $this->request->handler(),
                'method' => $this->request->verb(),
                'url' => $this->request->url(),
                'query' => $this->request->server('QUERY_STRING'),
            ],
            'performance' => [
                'execute_time' => $this->request->elapsed(),
                'memory_usage' => $memory_usage,
                'system_time' => date('Y-m-d H:i:s'),
            ],
            'server' => [
                'ip' => $this->request->server('SERVER_ADDR'),
                'client_ip' => $this->request->ip(),
                'software' => $this->request->server('SERVER_SOFTWARE'),
            ],
            'php' => [
                'framework_version' => $this->frameworkVersion->version(),
                'app_version' => $this->app->version(),
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'loaded_ini' => php_ini_loaded_file(),
                'loaded_extensions' => implode(', ', $loaded_extensions),
                'opcache_enable' => ini_get('opcache.enable'),
                'opcache_enable_cli' => ini_get('opcache.enable_cli'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [];
    }
}
