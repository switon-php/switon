<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Tests\TestCase;
use Switon\Ai\Signal\UserAbortSignal;

#[CoversClass(UserAbortSignal::class)]
class UserAbortSignalTest extends TestCase
{
    public function testEncodesNameFieldsAndBody(): void
    {
        $message = new UserAbortSignal(['tools' => 'bash'], 'The user stopped the run.');

        $this->assertSame('user_abort', $message->name);
        $this->assertArrayNotHasKey('kind', $message->fields);
        $this->assertSame('user_abort', $message->label());
        $this->assertStringContainsString('[signal:user_abort]', $message->wire()[0]['text']);
        $this->assertStringContainsString('tools: bash', $message->wire()[0]['text']);
        $this->assertStringContainsString('The user stopped the run.', $message->wire()[0]['text']);
    }
}
