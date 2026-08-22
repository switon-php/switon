<?php

declare(strict_types=1);

namespace Switon\Search;

use Switon\Ai\AgentInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\FilesystemInterface;
use Switon\Yaml\YamlReaderInterface;

use function array_keys;
use function implode;
use function ksort;
use function pathinfo;

use const PATHINFO_FILENAME;
use const SORT_STRING;

/** Site registry. Later files replace the same id. */
class Sites implements SitesInterface
{
    #[Autowired] protected FilesystemInterface $filesystem;
    #[Autowired] protected YamlReaderInterface $yamlReader;

    /**
     * Site directories in override order.
     *
     * @var list<string>
     */
    #[Autowired] protected array $paths = [
        '@switon/search/resources/sites',
        '@resources/sites',
        '~/.switon/sites',
    ];

    /** @var array<string, Site> Registered sites, keyed by id. Filled by `load()`. */
    protected array $sites = [];

    /**
     * Every registered site, keyed by id.
     *
     * @return array<string, Site>
     */
    public function all(): array
    {
        return $this->sites;
    }

    /** Rescan directories. Later files replace the same id. Rows already registered stay. */
    public function reload(): void
    {
        foreach ($this->paths as $path) {
            foreach ($this->filesystem->glob($path . '/*.yml') as $file) {
                $site = $this->load($file);
                $this->sites[$site->id] = $site;
            }
        }
    }

    public function variables(AgentInterface $agent): array
    {
        $sites = $this->sites;
        ksort($sites, SORT_STRING);

        $rows = [];
        foreach ($sites as $id => $site) {
            $rows[] = [
                'id' => $id,
                'label' => $site->description,
            ];
        }

        return ['sites' => $rows];
    }

    public function has(string $id): bool
    {
        return isset($this->sites[$id]);
    }

    public function set(Site $site): void
    {
        $this->sites[$site->id] = $site;
    }

    public function get(string $id): Site
    {
        $site = $this->sites[$id] ?? null;
        if ($site === null) {
            Exception::raise('Unknown site "{id}". Supported: {supported}.', [
                'id' => $id,
                'supported' => implode(', ', array_keys($this->sites)),
            ]);
        }

        return $site;
    }

    /**
     * Read one site file.
     *
     * @throws Exception When the file is empty.
     */
    public function load(string $file): Site
    {
        $stem = pathinfo($file, PATHINFO_FILENAME);
        $meta = $this->yamlReader->load($file);
        if ($meta === []) {
            Exception::raise('Site file {id} is empty', ['id' => $stem]);
        }

        $site = Site::of($meta, $stem);
        $site->path = $file;

        return $site;
    }
}
