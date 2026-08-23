<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Agency\Observability\AgentConsole;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentConsole::class)]
class StripEnvelopeCaller extends AgentConsole
{
    public function strip(string $content): string
    {
        return $this->stripEnvelopeTags($content);
    }
}

class AgentConsoleEnvelopeTest extends TestCase
{
    public function testStripsRedactedThinking(): void
    {
        $input = "<think>plan</think>\n\n2025-01-01 1:1:1";

        $this->assertSame('2025-01-01 1:1:1', (new StripEnvelopeCaller())->strip($input));
    }

    public function testStripsThinkAndThinkingBlocks(): void
    {
        $input = '<' . 'think' . ">hidden</" . 'think' . ">\n<thinking>more</thinking>\n\n2025-01-01 1:1:1";

        $this->assertSame('2025-01-01 1:1:1', (new StripEnvelopeCaller())->strip($input));
    }

    public function testLeavesPlainPayloadUntouched(): void
    {
        $this->assertSame('2025-01-01 1:1:1', (new StripEnvelopeCaller())->strip('2025-01-01 1:1:1'));
        $this->assertSame('findings line', (new StripEnvelopeCaller())->strip('findings line'));
    }
}
