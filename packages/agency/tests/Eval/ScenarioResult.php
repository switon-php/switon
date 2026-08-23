<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Eval;

/** One eval scenario outcome. */
class ScenarioResult
{
    /**
     * @param list<string> $failures
     */
    public function __construct(
        public string $id,
        public bool $passed,
        public array $failures = [],
        public string $assistant = '',
        /** @var array<string, int|string|bool|null> */
        public array $metrics = [],
    ) {
    }

    public static function pass(string $id, string $assistant = '', array $metrics = []): self
    {
        return new self($id, true, [], $assistant, $metrics);
    }

    /**
     * @param list<string> $failures
     * @param array<string, int|string|bool|null> $metrics
     */
    public static function fail(string $id, array $failures, string $assistant = '', array $metrics = []): self
    {
        return new self($id, false, $failures, $assistant, $metrics);
    }
}
