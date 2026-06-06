<?php

declare(strict_types=1);

namespace Switon\Inspector\Tests\Fixtures;

class TestableDataDump extends \Switon\Inspector\DataDump
{
    public function testFormatMessage(mixed $message): string
    {
        return $this->formatMessage($message);
    }

    /**
     * @param array<int, array<string, mixed>> $traces
     *
     * @return array<string, mixed>
     */
    public function testGetLocation(array $traces): array
    {
        return $this->getLocation($traces);
    }
}
