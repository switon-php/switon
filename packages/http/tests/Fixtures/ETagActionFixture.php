<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Fixtures;

use Switon\Http\Attribute\ETag;

final class ETagActionFixture
{
    #[ETag(field: 'updated_at')]
    public function withField(): array
    {
        return ['id' => 1, 'updated_at' => '2024-01-19'];
    }

    #[ETag]
    public function withoutField(): array
    {
        return ['data' => 'test'];
    }

    #[ETag(field: 'updated_at')]
    public function with304Response(): array
    {
        return ['id' => 1, 'updated_at' => '2024-01-19'];
    }

    #[ETag]
    public function withEmptyContent(): void
    {
    }

    #[ETag(field: 'updated_at')]
    public function fieldNotFoundFallbackToHash(): array
    {
        return ['id' => 1, 'name' => 'John'];
    }

    #[ETag(field: 'field')]
    public function withNonJsonContent(): string
    {
        return 'plain text content';
    }
}
