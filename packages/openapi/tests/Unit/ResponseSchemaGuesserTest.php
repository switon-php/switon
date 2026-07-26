<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit;

use Switon\OpenApi\Tests\TestCase;
use Switon\OpenApi\Tests\Unit\Fixtures\RepoOnlyNoReturnControllerFixture;
use Switon\OpenApi\Tests\Unit\Fixtures\ReturnTypeSamples;

class ResponseSchemaGuesserTest extends TestCase
{
    public function testGuessUsesReturnTypeInferenceFirst(): void
    {
        $schema = $this->guessResponseSchema(ReturnTypeSamples::class, 'editAction');

        $this->assertIsArray($schema);
        $this->assertSame('object', $schema['type'] ?? null);
        $this->assertArrayHasKey('properties', $schema);
    }

    public function testGuessReturnsNullWhenReturnTypeUnknown(): void
    {
        $this->assertNull($this->guessResponseSchema(RepoOnlyNoReturnControllerFixture::class, 'indexAction'));
    }
}
