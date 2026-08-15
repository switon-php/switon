<?php

declare(strict_types=1);

namespace Switon\Llm\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Llm\Http\Curl;
use Switon\Llm\Tests\TestCase;

#[CoversClass(Curl::class)]
class CurlTest extends TestCase
{
    public function testReadsALocalFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'llm-curl-');
        file_put_contents($path, 'ok');

        $curl = new Curl();
        $handle = $curl->init();
        $this->assertNotFalse($handle);
        $this->assertTrue($curl->setopt($handle, CURLOPT_URL, 'file://' . $path));
        $this->assertTrue($curl->setopt($handle, CURLOPT_RETURNTRANSFER, true));

        $this->assertSame('ok', $curl->exec($handle));
        $this->assertSame(0, $curl->errno($handle));
        $this->assertSame('', $curl->error($handle));
        $this->assertSame(0, $curl->getinfo($handle, CURLINFO_RESPONSE_CODE));

        unlink($path);
    }
}
