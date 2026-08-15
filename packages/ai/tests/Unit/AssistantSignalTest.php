<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Signal\AssistantSignal;
use Switon\Ai\Tests\TestCase;

#[CoversClass(AssistantSignal::class)]
class AssistantSignalTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $original = new AssistantSignal('demo', ['k' => 'v'], 'body');
        $decoded = AssistantSignal::decode($original->jsonSerialize());

        $this->assertSame('assistant', $decoded->role);
        $this->assertSame($original->name, $decoded->name);
        $this->assertSame($original->fields, $decoded->fields);
        $this->assertSame($original->body, $decoded->body);
        $this->assertSame($original->text(), $decoded->text());
    }
}
