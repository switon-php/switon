<?php

declare(strict_types=1);

namespace Switon\Tooling\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Tooling\Exception\ArgumentBindException;
use Switon\Tooling\Exception\ArgumentException;
use Switon\Tooling\Exception\ArgumentInvalidValueException;
use Switon\Tooling\Exception\ArgumentRequiredException;

class ArgumentExceptionTest extends TestCase
{
    public function testRequiredRaiseSubstitutesFieldIntoMessage(): void
    {
        try {
            ArgumentRequiredException::raise('{field} required', ['field' => 'message']);
            $this->fail('expected ArgumentRequiredException');
        } catch (ArgumentRequiredException $e) {
            $this->assertInstanceOf(ArgumentException::class, $e);
            $this->assertInstanceOf(ArgumentBindException::class, $e);
            $this->assertStringContainsString('message', $e->getMessage());
            // Placeholder keys are consumed by Core\\Exception and omitted from context.
            $this->assertSame([], $e->getContext());
        }
    }

    public function testInvalidValueRaiseKeepsNonPlaceholderContext(): void
    {
        try {
            ArgumentInvalidValueException::raise(
                '{field} value invalid',
                ['field' => 'seconds', 'got' => 0],
            );
            $this->fail('expected ArgumentInvalidValueException');
        } catch (ArgumentInvalidValueException $e) {
            $this->assertStringContainsString('seconds', $e->getMessage());
            $this->assertSame(['got' => 0], $e->getContext());
        }
    }
}
