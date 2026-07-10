<?php

declare(strict_types=1);

namespace Switon\Inspector;

use Switon\Core\Attribute\Autowired;
use Switon\Core\FilesystemInterface;

use function in_array;
use function is_string;
use function strtr;

/**
 * Renders inspector HTML with collector tab scripts.
 *
 * Use when serving <code>?__inspector=*.html</code> responses.
 *
 * Configure with:
 * - <code>pageTemplate</code> for the main HTML file
 * - <code>tabs</code> for collector tab script mapping
 *
 * @see \Switon\Inspector\HtmlRendererInterface
 */
class HtmlRenderer implements HtmlRendererInterface
{
    protected const string BUILTIN_TABS_ROOT = '@switon.inspector.resources/templates';

    #[Autowired] protected FilesystemInterface $filesystem;
    #[Autowired] protected CollectorDiscoveryInterface $discovery;

    /** Main inspector HTML template path. */
    #[Autowired] protected string $pageTemplate = '@switon.inspector.resources/templates/inspector.html';
    /** @var array<int|string, string> Collector tab template configuration. */
    #[Autowired] protected array $tabs = [
        'app',
        'basic',
        'dependencies',
        'event-mappings',
        'events',
        'files',
        'listener-stats',
        'logger',
        'providers',
        'renderer',
        'router',
        'sql',
    ];

    /** {@inheritDoc} */
    public function render(string $data): string
    {
        $template = $this->filesystem->read($this->pageTemplate);
        $componentScripts = $this->collectComponentScripts();

        return strtr($template, [
            'INSPECTOR_DATA' => $data,
            '<!-- COLLECTOR_COMPONENTS -->' => $componentScripts,
        ]);
    }

    /**
     * Collects JavaScript component scripts for resolved collectors.
     */
    protected function collectComponentScripts(): string
    {
        $scripts = '';

        // Load templates for discovered collectors
        foreach ($this->discovery->resolve() as $key => $collector) {
            // Check if configured: associative array (key => value) or indexed array (in_array check)
            if (isset($this->tabs[$key])) {
                $config = $this->tabs[$key];
                if (!is_string($config) || empty($config)) {
                    continue;
                }
                // If config contains a path separator, use it as a full path.
                // Otherwise treat config as component name and auto-assemble path.
                if (str_contains($config, '/') || str_contains($config, '\\')) {
                    $templatePath = $config;
                } else {
                    $templatePath = self::BUILTIN_TABS_ROOT . '/' . $config . '-tab.js';
                }
            } elseif (in_array($key, $this->tabs, true)) {
                // Indexed array: value equals key, auto-assemble path
                $templatePath = self::BUILTIN_TABS_ROOT . '/' . $key . '-tab.js';
            } else {
                continue;
            }

            $scripts .= $this->filesystem->read($templatePath) . "\n";
        }

        return $scripts;
    }
}
