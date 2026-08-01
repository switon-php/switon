<?php

declare(strict_types=1);

namespace Switon\Mcp\Tests\Unit\Transport;

use Switon\Mcp\Transport\StdioTransport;

class TestableStdioTransport extends StdioTransport
{
    /**
     * @param resource $stream
     */
    public function readStream($stream): ?array
    {
        return $this->readFromStream($stream);
    }

    public function triggerLogStderr(string $message): void
    {
        $this->logStderr($message);
    }
}
