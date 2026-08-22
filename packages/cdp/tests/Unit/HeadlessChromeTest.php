<?php

declare(strict_types=1);

namespace Switon\Cdp\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Switon\Cdp\Exception;
use Switon\Cdp\HeadlessChrome;

class HeadlessChromeTest extends TestCase
{
    #[Test]
    public function openRejectsEmptyUrl(): void
    {
        $chrome = new HeadlessChrome();
        $this->expectException(Exception::class);
        $chrome->open('   ');
    }

    #[Test]
    public function requestRejectsEmptyMethod(): void
    {
        $chrome = new HeadlessChrome();
        $this->expectException(Exception::class);
        $chrome->request('  ', 'https://example.com/');
    }

    #[Test]
    public function requestRejectsEmptyUrl(): void
    {
        $chrome = new HeadlessChrome();
        $this->expectException(Exception::class);
        $chrome->request('GET', '   ');
    }
}
