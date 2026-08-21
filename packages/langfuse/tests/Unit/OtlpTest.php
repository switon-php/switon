<?php

declare(strict_types=1);

namespace Switon\Langfuse\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Langfuse\Observability\Span;
use Switon\Langfuse\Otlp;

#[CoversClass(Otlp::class)]
class OtlpTest extends \PHPUnit\Framework\TestCase
{
    public function testTraceIdStripsUuidHyphens(): void
    {
        $this->assertSame(
            '1ea435b183df4130a57ca97bd9848d96',
            Otlp::traceId('1ea435b1-83df-4130-a57c-a97bd9848d96'),
        );
    }

    public function testTraceIdHashesNonUuidSeedStably(): void
    {
        $first = Otlp::traceId('turn-1');
        $second = Otlp::traceId('turn-1');

        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first);
        $this->assertNotSame($first, Otlp::traceId('turn-2'));
    }

    public function testSpanIdStaysSixteenHexChars(): void
    {
        $spanId = Otlp::spanId('c1', 'suspend');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $spanId);
        $this->assertNotSame($spanId, Otlp::spanId('c1'));
    }

    public function testUnixNanoKeepsMillisecondPrecisionAndCarriesOnRounding(): void
    {
        // The framework clock is second-precision with 3 decimals, so milliseconds are exact.
        $this->assertSame('1700000000123000000', Otlp::unixNano(1_700_000_000.123));
        $this->assertSame('1700000000000000000', Otlp::unixNano(1_700_000_000.0));

        // A float that rounds to a whole extra second must carry into the seconds digits.
        $this->assertSame('1700000002000000000', Otlp::unixNano(1_700_000_001.9999999999));
        $this->assertSame('1700000001000000000', Otlp::unixNano(1_700_000_000.9999995));
        $this->assertSame('2023-11-14T22:13:21.000Z', Otlp::isoFromUnix(1_700_000_000.9996));
    }

    public function testPayloadEncodesSpansWithParentAndErrorStatus(): void
    {
        $payload = Otlp::payload('switon', [
            new Span('generation', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', '1111111111111111', 1.0, 2.5, [
                'langfuse.observation.type' => 'generation',
            ]),
            new Span(
                'read',
                'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                '2222222222222222',
                2.0,
                2.2,
                ['langfuse.observation.type' => 'tool'],
                '1111111111111111',
                'disk full',
                true,
            ),
        ]);

        $resourceSpan = $payload['resourceSpans'][0];
        $this->assertSame(
            [['key' => 'service.name', 'value' => ['stringValue' => 'switon']]],
            $resourceSpan['resource']['attributes'],
        );

        $spans = $resourceSpan['scopeSpans'][0]['spans'];
        $this->assertCount(2, $spans);
        $this->assertSame('generation', $spans[0]['name']);
        $this->assertSame(Otlp::KIND_INTERNAL, $spans[0]['kind']);
        $this->assertSame('1000000000', $spans[0]['startTimeUnixNano']);
        $this->assertSame('2500000000', $spans[0]['endTimeUnixNano']);
        $this->assertArrayNotHasKey('parentSpanId', $spans[0]);
        $this->assertSame(['code' => 0], $spans[0]['status']);

        $this->assertSame('1111111111111111', $spans[1]['parentSpanId']);
        $this->assertSame(
            ['code' => Otlp::STATUS_ERROR, 'message' => 'disk full'],
            $spans[1]['status'],
        );
    }

    public function testAttributesMapScalarsAndDropNulls(): void
    {
        $attributes = Otlp::attributes([
            'langfuse.observation.type' => 'generation',
            'retries' => 2,
            'temperature' => 0.5,
            'cached' => true,
            'none' => null,
            'tags' => ['a', 'b'],
        ]);

        $this->assertSame([
            ['key' => 'langfuse.observation.type', 'value' => ['stringValue' => 'generation']],
            ['key' => 'retries', 'value' => ['intValue' => '2']],
            ['key' => 'temperature', 'value' => ['doubleValue' => 0.5]],
            ['key' => 'cached', 'value' => ['boolValue' => true]],
            ['key' => 'tags', 'value' => ['arrayValue' => ['values' => [
                ['stringValue' => 'a'],
                ['stringValue' => 'b'],
            ]]]],
        ], $attributes);
    }
}
