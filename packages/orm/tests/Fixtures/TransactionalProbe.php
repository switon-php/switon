<?php

declare(strict_types=1);

namespace Switon\Orm\Tests\Fixtures;

use Switon\Db\TransactionManagerInterface;
use Switon\Orm\Attribute\Transactional;

class TransactionalProbe extends Transactional
{
    public function setTransactionManagerForTest(TransactionManagerInterface $transactionManager): static
    {
        $this->transactionManager = $transactionManager;

        return $this;
    }
}
