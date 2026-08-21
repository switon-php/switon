<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use Switon\Ai\Exception\ToolInvalidArgumentException;
use Switon\Ai\Tests\TestCase;

class ToolInvalidArgumentExceptionTest extends TestCase
{
    public function testRaiseKeepsNonPlaceholderContext(): void
    {
        try {
            ToolInvalidArgumentException::raise(
                '{field} value invalid',
                ['field' => 'seconds', 'got' => 0],
            );
            $this->fail('expected ToolInvalidArgumentException');
        } catch (ToolInvalidArgumentException $e) {
            $this->assertStringContainsString('seconds', $e->getMessage());
            $this->assertSame(['got' => 0], $e->getContext());
        }
    }
}
