<?php

declare(strict_types=1);

namespace Switon\Mail\Tests\Fixtures;

use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
class StreamSocketPair
{
    /**
     * @return array{0: resource, 1: resource}
     */
    public static function createUnixPair(TestCase $test): array
    {
        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $test->markTestSkipped('stream_socket_pair(STREAM_PF_UNIX) is not available on this platform.');
        }

        return $pair;
    }
}
