<?php

declare(strict_types=1);

namespace Switon\Langfuse;

use Switon\Core\Json;
use Switon\Langfuse\Observability\Span;

use function floor;
use function hash;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function preg_replace;
use function round;
use function sprintf;
use function strtolower;
use function substr;

/**
 * OTLP/HTTP JSON encoding for `POST /api/public/otel/v1/traces`.
 *
 * Guidance: ids and times must already be hex/Unix seconds; this class only serializes.
 * Road-signs:
 * - Trace/span ids: {@see self::traceId()} narrows a UUID, hashes anything else
 * - Times: {@see self::unixNano()} keeps nanosecond digits as a string, never a float
 * - Values: {@see self::attributes()} maps PHP scalars onto OTLP `*Value` members
 */
class Otlp
{
    /** OTLP `SPAN_KIND_INTERNAL`. */
    public const int KIND_INTERNAL = 1;

    /** OTLP `STATUS_CODE_ERROR`. */
    public const int STATUS_ERROR = 2;

    /** 32 lowercase hex characters. A UUID is stripped; any other seed is hashed, so the id stays stable. */
    public static function traceId(string $seed): string
    {
        $hex = (string) preg_replace('/[^0-9a-f]/', '', strtolower($seed));

        return strlen($hex) === 32 ? $hex : substr(hash('sha256', $seed), 0, 32);
    }

    /** 16 lowercase hex characters, derived from a seed plus an optional purpose suffix. */
    public static function spanId(string $seed, string $purpose = ''): string
    {
        return substr(hash('sha256', $seed . '|' . $purpose), 0, 16);
    }

    /**
     * Unix seconds as OTLP nanoseconds, string-encoded to keep full precision.
     *
     * `float` seconds at 1.7e9 resolve about 240 ns, so nanos are rounded to the nearest
     * microsecond: honest about the clock instead of printing meaningless digits.
     */
    public static function unixNano(float $unix): string
    {
        $seconds = (int) floor($unix);
        $nanos = (int) round(round(($unix - $seconds) * 1_000_000_000, -3));
        if ($nanos >= 1_000_000_000) {
            $seconds++;
            $nanos -= 1_000_000_000;
        } elseif ($nanos < 0) {
            $nanos = 0;
        }

        return $seconds . sprintf('%09d', $nanos);
    }

    /** Unix seconds as the Langfuse ISO-8601 UTC string used by `completion_start_time`. */
    public static function isoFromUnix(float $unix): string
    {
        $seconds = (int) floor($unix);
        $millis = (int) round(($unix - $seconds) * 1000);
        if ($millis >= 1000) {
            $seconds++;
            $millis -= 1000;
        } elseif ($millis < 0) {
            $millis = 0;
        }

        return gmdate('Y-m-d\TH:i:s.', $seconds) . sprintf('%03d', $millis) . 'Z';
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return list<array{key: string, value: array<string, mixed>}>
     */
    public static function attributes(array $attributes): array
    {
        $rows = [];
        foreach ($attributes as $key => $value) {
            $encoded = self::value($value);
            if ($encoded !== null) {
                $rows[] = ['key' => (string) $key, 'value' => $encoded];
            }
        }

        return $rows;
    }

    /**
     * @param list<Span> $spans
     *
     * @return array<string, mixed>
     */
    public static function payload(string $serviceName, array $spans): array
    {
        $rows = [];
        foreach ($spans as $span) {
            $row = [
                'traceId' => $span->traceId,
                'spanId' => $span->spanId,
                'name' => $span->name,
                'kind' => self::KIND_INTERNAL,
                'startTimeUnixNano' => self::unixNano($span->startUnix),
                'endTimeUnixNano' => self::unixNano($span->endUnix),
                'attributes' => self::attributes($span->attributes),
                'status' => $span->isError
                    ? ['code' => self::STATUS_ERROR, 'message' => $span->statusMessage]
                    : ['code' => 0],
            ];
            if ($span->parentSpanId !== '') {
                $row['parentSpanId'] = $span->parentSpanId;
            }
            $rows[] = $row;
        }

        return [
            'resourceSpans' => [[
                'resource' => ['attributes' => self::attributes(['service.name' => $serviceName])],
                'scopeSpans' => [[
                    'scope' => ['name' => 'switon/langfuse'],
                    'spans' => $rows,
                ]],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function value(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return ['boolValue' => $value];
        }
        if (is_int($value)) {
            return ['intValue' => (string) $value];
        }
        if (is_float($value)) {
            return ['doubleValue' => $value];
        }
        if (is_string($value)) {
            return ['stringValue' => $value];
        }
        if (is_array($value)) {
            $values = [];
            foreach ($value as $item) {
                $encoded = self::value($item);
                if ($encoded !== null) {
                    $values[] = $encoded;
                }
            }

            return ['arrayValue' => ['values' => $values]];
        }

        return ['stringValue' => Json::stringify($value)];
    }
}
