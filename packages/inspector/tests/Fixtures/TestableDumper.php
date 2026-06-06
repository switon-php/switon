<?php

declare(strict_types=1);

namespace Switon\Inspector\Tests\Fixtures;

class TestableDumper extends \Switon\Inspector\Dumper
{
    /**
     * @param array<string, mixed> $properties
     *
     * @return array<string, mixed>
     */
    public function testNormalize(array $properties): array
    {
        return $this->normalize($properties);
    }
}
