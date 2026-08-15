<?php

declare(strict_types=1);

namespace Switon\Ai;

/**
 * Structured output until the terminal tool is called.
 *
 * Guidance: model driving stays outside this type; terminal tool naming is an implementation detail.
 * {@see self::ask()} opens a throwaway session. {@see self::instruct()} runs an agent the caller already started.
 *
 * Road-signs:
 * - Terminal tool: the agency implementation is itself the tool (`tmp_instruct_{object_id}`)
 * - Codes: {@see \Switon\Agency\Instruct\Response}
 */
interface InstructorInterface
{
    /**
     * One shot: open a session for `$profile`, run, shut it down, and unwrap `data`.
     *
     * @param array<string, array<string, mixed>> $properties Business JSON-schema properties nested under response `data`.
     *
     * @return array<string, mixed> Unwrapped business `data` when response `code` is ok.
     */
    public function ask(Profile $profile, string $content, array $properties): array;

    /**
     * Grant this call's terminal tool, run one delivery on an already-started agent, and unwrap `data` when `code` is ok.
     *
     * @param array<string, array<string, mixed>> $properties Business JSON-schema properties nested under response `data`.
     *
     * @return array<string, mixed> Unwrapped business `data` when response `code` is ok.
     */
    public function instruct(AgentInterface $agent, string $content, array $properties): array;
}
