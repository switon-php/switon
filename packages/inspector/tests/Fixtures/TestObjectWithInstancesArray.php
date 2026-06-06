<?php

declare(strict_types=1);

namespace Switon\Inspector\Tests\Fixtures;

use stdClass;

class TestObjectWithInstancesArray
{
    #[\Switon\Core\Attribute\Autowired(instances: true)]
    public array $instances = [];

    public function __construct()
    {
        $this->instances = [
            'key1' => new stdClass(),
            'key2' => new stdClass(),
            0 => new stdClass(),
        ];
    }
}
