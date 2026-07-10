<?php

declare(strict_types=1);

namespace Switon\Inspector\Tests\Fixtures;

use WeakMap;
use stdClass;

class TestObjectWithWeakMap
{
    public WeakMap $weakMap;
    protected object $mapKey;

    public function __construct()
    {
        $this->weakMap = new WeakMap();
        $this->mapKey = new stdClass();
        $this->weakMap[$this->mapKey] = 42;
    }
}
