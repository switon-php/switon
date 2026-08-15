<?php

declare(strict_types=1);

namespace Switon\Llm\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use Switon\Llm\Exception\HttpStatusException;

class HttpStatusExceptionTest extends TestCase
{
    public function testInterruptMessageMapsAuthFailure(): void
    {
        $error = HttpStatusException::of('ignored', ['status_code' => 401, 'body' => '']);

        $this->assertSame('LLM API authentication failed.', $error->interruptMessage());
    }

    public function testInterruptMessageMapsCommonStatuses(): void
    {
        $cases = [
            403 => 'LLM API access forbidden.',
            429 => 'LLM API rate limit exceeded.',
            502 => 'LLM API gateway error.',
            503 => 'LLM API temporarily unavailable.',
        ];

        foreach ($cases as $status => $expected) {
            $error = HttpStatusException::of('upstream', ['status_code' => $status, 'body' => '']);
            $this->assertSame($expected, $error->interruptMessage());
        }
    }

    public function testInterruptMessageFallsBackToStatusAndBody(): void
    {
        $error = HttpStatusException::of('provider error', ['status_code' => 418, 'body' => 'teapot']);

        $this->assertStringContainsString('418', $error->interruptMessage());
        $this->assertStringContainsString('teapot', $error->interruptMessage());
    }
}
