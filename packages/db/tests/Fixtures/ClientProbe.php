<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Fixtures;

use Switon\Db\Client;
use Switon\Db\ConnectionInterface;

class ClientProbe extends Client
{
    public function transactionLevelForTest(): int
    {
        return $this->transaction_level;
    }

    public function setTransactionLevelForTest(int $level): static
    {
        $this->transaction_level = $level;

        return $this;
    }

    public function pinnedConnectionForTest(): ?ConnectionInterface
    {
        $connection = $this->connection;

        return $connection instanceof ConnectionInterface ? $connection : null;
    }

    public function setPinnedConnectionForTest(?ConnectionInterface $connection): static
    {
        $this->connection = $connection;

        return $this;
    }
}
