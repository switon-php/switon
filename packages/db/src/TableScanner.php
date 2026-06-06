<?php

declare(strict_types=1);

namespace Switon\Db;

use Switon\Core\Attribute\Autowired;
use Switon\Di\NamedLookupInterface;

use function array_values;
use function fnmatch;

/**
 * Scans tables across configured DB connections with optional blacklist filtering.
 *
 * Configure blacklist patterns (for example <code>migration_*</code>) through <code>$blacklist</code>.
 *
 * @see \Switon\Db\TableScannerInterface
 * @see \Switon\Db\ClientInterface
 */
class TableScanner implements TableScannerInterface
{
    /** @var NamedLookupInterface<ClientInterface> */
    #[Autowired] protected NamedLookupInterface $namedLookup;

    /** @var list<string> Table-name blacklist patterns matched via fnmatch(). */
    #[Autowired] protected array $blacklist = [];

    /** {@inheritDoc} */
    public function scan(?string $connection = null): array
    {
        $result = [];

        if ($connection !== null) {
            // Scan specific connection
            $db = $this->namedLookup->by(ClientInterface::class, $connection);
            $tables = $this->filterTables($db->getTables());
            $result[$connection] = $tables;
        } else {
            // Scan all connections
            $connectionNames = $this->namedLookup->names(ClientInterface::class);
            foreach ($connectionNames as $connName) {
                $db = $this->namedLookup->by(ClientInterface::class, $connName);
                $tables = $this->filterTables($db->getTables());
                $result[$connName] = $tables;
            }
        }

        return $result;
    }

    /**
     * Filters table names with wildcard blacklist rules.
     *
     * @param list<string> $tables
     *
     * @return list<string>
     */
    protected function filterTables(array $tables): array
    {
        if (empty($this->blacklist)) {
            return array_values($tables);
        }

        $filtered = array_filter($tables, function (string $table) {
            foreach ($this->blacklist as $pattern) {
                if (fnmatch($pattern, $table)) {
                    return false; // Matched blacklist, exclude
                }
            }
            return true;
        });

        return array_values($filtered);
    }
}
