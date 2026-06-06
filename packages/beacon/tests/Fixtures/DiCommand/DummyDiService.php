<?php

declare(strict_types=1);

namespace Switon\Beacon\Tests\Fixtures\DiCommand;

use Switon\Core\Attribute\Autowired;
use stdClass;

class DummyDiService extends DummyDiBase implements DummyDiServiceInterface
{
    #[Autowired] protected int $retries = 3;

    /** @var array<int, string> */
    #[Autowired] protected array $servers = ['a', 'b'];

    #[Autowired] protected stdClass $ignored;

    public function __construct()
    {
        $this->ignored = new stdClass();
    }
}
