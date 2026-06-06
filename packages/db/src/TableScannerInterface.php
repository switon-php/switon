<?php

declare(strict_types=1);

namespace Switon\Db;

/**
 * Contract for listing tables across one or more configured DB connections.
 *
 * @see \Switon\Db\TableScanner
 * @see \Switon\Db\ClientInterface
 */
interface TableScannerInterface
{
    /**
     * Return table names keyed by connection name.
     *
     * Pass a connection name to scan a single client, or null to scan all named clients.
     *
     * @return array<string, list<string>>
     */
    public function scan(?string $connection = null): array;
}
