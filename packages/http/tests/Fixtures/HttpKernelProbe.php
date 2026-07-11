<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Fixtures;

use Switon\Http\Kernel;

class HttpKernelProbe extends Kernel
{
    /**
     * @return array<string, mixed>
     */
    public function defaultServicesForTest(): array
    {
        return $this->services;
    }
}
