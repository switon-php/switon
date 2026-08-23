<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Eval\Scenarios;

/** Skills eval scenario catalog. */
class SkillsScenarios
{
    public const string LIVE_DEMO_SECRET = 'SKILL-LIVE-7742';

    /**
     * @return list<array{
     *     id: string,
     *     user: string,
     *     skills_root: string,
     *     expect: array<string, mixed>,
     * }>
     */
    public static function liveDemo(): array
    {
        $root = dirname(__DIR__, 2) . '/manual/skills';

        return [
            [
                'id' => 'live-demo-secret',
                'user' => 'What is the live demo verification secret? Reply with only the code.',
                'skills_root' => $root,
                'expect' => [
                    'skill_load' => true,
                    'skill_root_line' => true,
                    'skill_load_body_contains' => '# Live demo skill',
                    'read_contains' => self::LIVE_DEMO_SECRET,
                    'answer_contains' => self::LIVE_DEMO_SECRET,
                    'tools_called' => ['skill_load', 'read'],
                ],
            ],
        ];
    }

    /**
     * Stub-only scenario: deterministic tool chain (no catalog routing under test).
     *
     * @return list<array{
     *     id: string,
     *     user: string,
     *     skill_name: string,
     *     secret: string,
     *     expect: array<string, mixed>,
     * }>
     */
    public static function stubChain(): array
    {
        return [
            [
                'id' => 'stub-skill-chain',
                'user' => 'Give me the eval stub secret code only.',
                'skill_name' => 'eval-stub',
                'secret' => 'EVAL-STUB-991',
                'expect' => [
                    'skill_load' => true,
                    'skill_root_line' => true,
                    'skill_load_body_contains' => '# Eval stub',
                    'read_contains' => 'EVAL-STUB-991',
                    'answer_contains' => 'EVAL-STUB-991',
                ],
            ],
        ];
    }
}
