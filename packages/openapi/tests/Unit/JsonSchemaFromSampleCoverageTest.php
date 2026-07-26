<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\OpenApi\JsonSchemaFromSample;
use stdClass;

class JsonSchemaFromSampleCoverageTest extends TestCase
{
    public function testInfersNullArrayObjectAndUriSamples(): void
    {
        $infer = new JsonSchemaFromSample();

        $this->assertSame(['nullable' => true], $infer->inferSchema(null));
        $this->assertSame(['type' => 'array'], $infer->inferSchema([]));
        $this->assertSame(['type' => 'string', 'description' => 'unsupported PHP type in sample'], $infer->inferSchema(new stdClass()));
        $this->assertSame(['type' => 'string', 'format' => 'uri'], $infer->inferSchema('https://example.com/docs'));
    }

    public function testFallsBackToFirstItemWhenListEncodingFails(): void
    {
        $infer = new JsonSchemaFromSample();
        $bad = "a" . chr(0xB1);
        $schema = $infer->inferSchema([$bad, 'ok']);

        $this->assertSame('array', $schema['type']);
        $this->assertSame(['type' => 'string'], $schema['items']);
    }

    public function testListSchemaDedupesIdenticalItemSchemas(): void
    {
        $infer = new JsonSchemaFromSample();
        $schema = $infer->inferSchema([1, 1, 2, 2, 3]);

        $this->assertSame('array', $schema['type']);
        $this->assertSame(['type' => 'integer'], $schema['items']);
    }

    public function testEmptyStringHasNoFormat(): void
    {
        $infer = new JsonSchemaFromSample();

        $this->assertSame(['type' => 'string'], $infer->inferSchema(''));
    }

    public function testPlainStringWithoutKnownFormat(): void
    {
        $infer = new JsonSchemaFromSample();

        $this->assertSame(['type' => 'string'], $infer->inferSchema('hello'));
    }

    public function testListSchemaDedupesDuplicateEntriesWhenBuildingOneOf(): void
    {
        $infer = new JsonSchemaFromSample();
        $schema = $infer->inferSchema([1, 'a', 1]);

        $this->assertSame('array', $schema['type']);
        $this->assertArrayHasKey('oneOf', $schema['items']);
        $this->assertCount(2, $schema['items']['oneOf']);
    }

    public function testListSchemaFallsBackWhenJsonEncodingThrows(): void
    {
        $infer = new JsonSchemaFromSample();
        $schema = $infer->inferSchema([NAN, 2.5]);

        $this->assertSame('array', $schema['type']);
        $this->assertSame(['type' => 'number'], $schema['items']);
    }

    public function testListSchemaSortsNestedObjectPropertyKeys(): void
    {
        $infer = new JsonSchemaFromSample();
        $schema = $infer->inferSchema([['z' => 1, 'a' => 2], ['b' => 3]]);

        $this->assertSame('array', $schema['type']);
        $this->assertArrayHasKey('oneOf', $schema['items']);
        $first = $schema['items']['oneOf'][0]['properties'] ?? [];
        $this->assertArrayHasKey('a', $first);
        $this->assertArrayHasKey('z', $first);
    }
}
