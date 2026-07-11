<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Http\Attribute\WsCloseMapping;
use Switon\Http\Attribute\WsErrorMapping;
use Switon\Http\Attribute\WsMapping;
use Switon\Http\Attribute\WsMessageMapping;
use Switon\Http\Attribute\WsOpenMapping;

class WsMappingTest extends TestCase
{
    public function testEventMappingsExposeTheirVerbsAndIgnorePath(): void
    {
        $cases = [
            [new WsOpenMapping('/chat'), WsMapping::VERB_OPEN],
            [new WsMessageMapping('message'), WsMapping::VERB_MESSAGE],
            [new WsCloseMapping(null), WsMapping::VERB_CLOSE],
            [new WsErrorMapping(['a', 'b']), WsMapping::VERB_ERROR],
        ];

        foreach ($cases as [$mapping, $verb]) {
            $this->assertSame($verb, $mapping->getVerb());
            $this->assertSame('', $mapping->getPath());
        }
    }
}
