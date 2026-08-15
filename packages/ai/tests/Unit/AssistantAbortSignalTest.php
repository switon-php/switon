<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Signal\AssistantAbortSignal;
use Switon\Ai\Tests\TestCase;

#[CoversClass(AssistantAbortSignal::class)]
class AssistantAbortSignalTest extends TestCase
{
    public function testEncodesNameKindAndBody(): void
    {
        $signal = new AssistantAbortSignal(AssistantAbortSignal::KIND_CANCELLED, body: 'The run was cancelled.');

        $this->assertSame('assistant_abort', $signal->name);
        $this->assertSame(AssistantAbortSignal::KIND_CANCELLED, $signal->kind);
        $this->assertSame('cancelled', $signal->kind);
        $this->assertArrayNotHasKey('kind', $signal->fields);
        $this->assertStringContainsString('[signal:assistant_abort.cancelled]', $signal->wire()[0]['text']);
        $this->assertStringNotContainsString('kind: cancelled', $signal->wire()[0]['text']);
        $this->assertStringContainsString('The run was cancelled.', $signal->wire()[0]['text']);
        $this->assertSame('assistant_abort.cancelled', $signal->label());
    }

    public function testDecodeRoundTrip(): void
    {
        $original = new AssistantAbortSignal('exception', ['x' => 1], 'boom');
        $decoded = AssistantAbortSignal::decode($original->jsonSerialize());

        $this->assertInstanceOf(AssistantAbortSignal::class, $decoded);
        $this->assertSame('exception', $decoded->kind);
        $this->assertSame('boom', $decoded->body);
        $this->assertSame(1, $decoded->fields['x']);
    }
}
