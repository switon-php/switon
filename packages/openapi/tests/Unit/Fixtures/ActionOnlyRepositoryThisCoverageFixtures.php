<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit\Fixtures;

// Intentionally left as a coverage-only placeholder file.
// Fixtures previously stored here moved to per-class files for PSR-4 autoloading.

class ActionOnlyRepositoryBuiltinFixture
{
    public int $legacy = 0;

    public function indexAction(): array
    {
        return ['legacy' => $this->legacy];
    }
}

class ActionOnlyRepositoryMissingPropertyFixture
{
    public function indexAction(): array
    {
        return $this->missingRepository->all();
    }
}

class ActionOnlyRepositoryNoThisFixture
{
    public function indexAction(): array
    {
        return [];
    }
}
