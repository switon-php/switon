<?php

declare(strict_types=1);

namespace Switon\McpClient\Tests\Unit\Transport;

use Switon\McpClient\Transport\StdioTransport;

class TestableStdioTransport extends StdioTransport
{
    /**
     * @param resource $stdin
     * @param resource $stdout
     * @param resource|null $stderr
     */
    public function stubOpen($stdin, $stdout, $stderr = null): void
    {
        $this->stdin = $stdin;
        $this->stdout = $stdout;
        $this->stderr = $stderr;
    }

    public function setMaxLineBytes(int $bytes): void
    {
        $this->maxLineBytes = $bytes;
    }

    public function setReadTimeout(float $seconds): void
    {
        $this->readTimeout = $seconds;
    }
}
