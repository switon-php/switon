<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Eval\Scenarios;

/** 1–100 guessing-game live eval defaults. */
class GuessingGameScenarios
{
    /**
     * @return array{id: string, max_runs: int}
     */
    public static function defaultGame(): array
    {
        return [
            'id' => 'guessing-1-100',
            'max_runs' => 8,
        ];
    }
}
