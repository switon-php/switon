<?php

declare(strict_types=1);

namespace Switon\Browser\Tests\Unit;

use Switon\Browser\HeadedBrowserInterface;
use Switon\Browser\HeadedBrowsersInterface;

trait HeadedBrowsersStub
{
    private function browsers(HeadedBrowserInterface $browser): HeadedBrowsersInterface
    {
        $tabs = $this->createStub(HeadedBrowsersInterface::class);
        $tabs->method('tab')->willReturn($browser);
        $tabs->method('open')->willReturn($browser);

        return $tabs;
    }
}
