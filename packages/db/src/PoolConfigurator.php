<?php

declare(strict_types=1);

namespace Switon\Db;

use Switon\Core\Attribute\Autowired;
use Switon\Core\Runtime;
use Switon\Pooling\PoolManagerInterface;

use function array_shift;
use function count;
use function explode;
use function is_string;
use function method_exists;
use function parse_url;
use function preg_match;
use function random_int;
use function shuffle;
use function str_contains;
use function str_replace;
use function strpos;
use function substr;
use function trim;

/**
 * Builds DB pool settings from connection URIs and registers them in PoolManager.
 *
 * Guidance: Prefer URI options for pool topology and timeouts; avoid duplicating the same routing rules in application code.
 *
 * Road-signs:
 * - configure() extracts timeout, pool_size, and prefix first
 * - parseUris() expands single, host-list, brace, or URI-list formats
 * - parsePoolSizes() splits master/slave pool counts
 * - configureSlavePools() adds readonly slave entries
 * - PoolConfiguration returns timeout, prefix, and hasSlave to Client
 *
 * @see \Switon\Db\PoolConfiguratorInterface
 * @see \Switon\Db\Client
 */
class PoolConfigurator implements PoolConfiguratorInterface
{
    /** @var PoolManagerInterface<object, ConnectionInterface> */
    #[Autowired] protected PoolManagerInterface $poolManager;

    /**
     * URI parser map by input format.
     *
     * @var array<string, string>
     */
    #[Autowired] protected array $parsers = [
        'brace' => 'parseBraceUris',
        'hosts' => 'parseHostUris',
        'list' => 'parseListUris',
        'single' => 'parseSingleUri',
    ];

    /** {@inheritDoc} */
    public function configure(object $owner, string $uri): PoolConfiguration
    {
        // Parse URI parameters
        $timeout = 1.0;
        $poolSize = '4';
        $prefix = '';

        if (str_contains($uri, 'timeout=') && preg_match('#timeout=([\d.]+)#', $uri, $matches) === 1) {
            $timeout = (float)$matches[1];
        }

        if (preg_match('#pool_size=([\d/]+)#', $uri, $matches)) {
            $poolSize = $matches[1];
        }

        if (preg_match('#[?&]prefix=(\w+)#', $uri, $matches)) {
            $prefix = $matches[1];
        }

        // Parse URIs
        $uris = $this->parseUris($uri);

        // Parse pool sizes
        [$masterPoolSize, $slavePoolSize] = $this->parsePoolSizes($poolSize);

        // Configure master pool
        $masterUri = '';
        if ($uris[0] !== '') {
            $masterUri = (string)$uris[0];
            $this->poolManager->add($owner, [ConnectionInterface::class, ['uri' => $masterUri, 'owner' => $owner]], $masterPoolSize);
        }

        // Configure slave pools
        $slaveUris = [];
        $hasSlave = false;
        if (count($uris) > 1) {
            array_shift($uris);
            $slaveUris = $uris;
            $this->configureSlavePools($owner, $uris, $slavePoolSize);
            $hasSlave = true;
        }

        return new PoolConfiguration(
            timeout: $timeout,
            prefix: $prefix,
            hasSlave: $hasSlave,
        );
    }

    /**
     * Expands URI input into an ordered list (master first, then optional slaves).
     *
     * Supports brace host expansion and comma-separated host/URI lists.
     *
     * @return list<string>
     */
    protected function parseUris(string $uri): array
    {
        // Format 1: Brace expansion - mysql://user@{host1,host2}/db
        if (str_contains($uri, '{') && preg_match('#{[^}]+}#', $uri, $matches)) {
            return $this->parseBy('brace', $uri, $matches[0]);
        }

        if (str_contains($uri, ',')) {
            // Format 2 & 3: Comma-separated
            // Try to interpret comma in host part first; if host part is not a string,
            // fall back to treating the whole URI as a comma-separated list of URIs.
            $hosts = parse_url($uri, PHP_URL_HOST);

            // Format 2: Comma in host part - mysql://user@host1,host2/db
            if (is_string($hosts) && str_contains($hosts, ',')) {
                return $this->parseBy('hosts', $uri, $hosts);
            }

            // Format 3: Complete URIs - mysql://user@host1/db,mysql://user@host2/db
            return $this->parseBy('list', $uri);
        }

        // Single URI - no master-slave
        return $this->parseBy('single', $uri);
    }

    /**
     * Runs configured URI parser by parser key.
     *
     * @param ?string $hosts Brace placeholder or host segment when needed
     *
     * @return list<string>
     */
    protected function parseBy(string $key, string $uri, ?string $hosts = null): array
    {
        $parser = $this->parsers[$key] ?? null;
        if (!is_string($parser) || $parser === '' || !method_exists($this, $parser)) {
            return [$uri];
        }

        if ($key === 'brace' || $key === 'hosts') {
            return $this->{$parser}($uri, (string)$hosts);
        }

        return $this->{$parser}($uri);
    }

    /**
     * Expands brace host template, for example <code>mysql://{h1,h2}/db</code>.
     *
     * @return list<string>
     */
    protected function parseBraceUris(string $uri, string $hosts): array
    {
        $uris = [];
        foreach (explode(',', substr($hosts, 1, -1)) as $value) {
            $value = trim($value);
            if ($value !== '') {
                $uris[] = str_replace($hosts, $value, $uri);
            }
        }

        return $uris;
    }

    /**
     * Expands comma-separated hosts in one URI, for example <code>mysql://h1,h2/db</code>.
     *
     * @return list<string>
     */
    protected function parseHostUris(string $uri, string $hosts): array
    {
        $uris = [];
        foreach (explode(',', $hosts) as $value) {
            $value = trim($value);
            if ($value !== '') {
                $uris[] = str_replace($hosts, $value, $uri);
            }
        }

        return $uris;
    }

    /**
     * Parses comma-separated full URI list.
     *
     * @return list<string>
     */
    protected function parseListUris(string $uri): array
    {
        $uris = [];
        foreach (explode(',', $uri) as $value) {
            $value = trim($value);
            if ($value !== '') {
                $uris[] = $value;
            }
        }

        return $uris;
    }

    /**
     * Returns single URI as list.
     *
     * @return list<string>
     */
    protected function parseSingleUri(string $uri): array
    {
        return [$uri];
    }

    /** Parses pool size syntax: <code>4</code> or <code>4/8</code>. */
    /**
     * Parses pool size syntax: <code>4</code> or <code>4/8</code>.
     *
     * @return array{int, int}
     */
    protected function parsePoolSizes(string $poolSize): array
    {
        if (($pos = strpos($poolSize, '/')) === false) {
            $masterPoolSize = (int)$poolSize;
            $slavePoolSize = (int)$poolSize;
        } else {
            $masterPoolSize = (int)substr($poolSize, 0, $pos);
            $slavePoolSize = (int)substr($poolSize, $pos + 1);
        }

        return [$masterPoolSize, $slavePoolSize];
    }

    /**
     * Configures slave pools and ensures slave URIs include <code>readonly=1</code>.
     *
     * Coroutine mode uses weighted round-robin; non-coroutine mode picks one slave randomly.
     *
     * @param list<string> $uris
     */
    protected function configureSlavePools(object $owner, array $uris, int $slavePoolSize): void
    {
        // Ensure all slaves are marked readonly
        foreach ($uris as $index => $uri) {
            if (preg_match('#[?&]readonly\b#', $uri) !== 1) {
                $uris[$index] .= (str_contains($uri, '?') ? '&' : '?') . 'readonly=1';
            }
        }

        // Guard against empty URI array (all URIs filtered out)
        if (empty($uris)) {
            return;
        }

        if (Runtime::isCoroutineEnabled()) {
            // Coroutine mode: Round-robin across all slaves
            shuffle($uris);  // Randomize order for load distribution

            $this->poolManager->create($owner, count($uris) * $slavePoolSize, 'slave');
            // Add each slave multiple times for balanced pooling
            for ($i = 0; $i < $slavePoolSize; $i++) {
                foreach ($uris as $uri) {
                    $this->poolManager->add($owner, [ConnectionInterface::class, ['uri' => $uri, 'owner' => $owner]], 1, 'slave');
                }
            }
        } else {
            // Non-coroutine mode: Pick one slave randomly
            $uri = (string)$uris[random_int(0, count($uris) - 1)];
            $this->poolManager->add($owner, [ConnectionInterface::class, ['uri' => $uri, 'owner' => $owner]], 1, 'slave');
        }
    }
}
