<?php

declare(strict_types=1);

namespace Switon\Skill;

use Switon\Ai\PromptVariablesInterface;

/**
 * Skill catalog: lookup, runtime rows, and path refresh.
 *
 * Guidance: `reload()` rescans paths and keeps rows that are not on those paths.
 */
interface SkillsInterface extends PromptVariablesInterface
{
    /** Fail closed when missing. */
    public function get(string $id): Skill;

    public function has(string $id): bool;

    /** Composed skill block for this id. A missing id returns an unknown-id block. */
    public function block(string $id): string;

    /**
     * Load the skill in one directory.
     *
     * Null when `SKILL.md` is missing or has no frontmatter.
     */
    public function load(string $dir): ?Skill;

    /** Set a runtime skill under its id — last write wins, same resolution rules as a disk row. */
    public function set(Skill $skill): void;

    /**
     * Rescan configured paths and merge.
     * New and updated rows replace the same id. Rows that are not on those paths stay.
     * Removed files stay until process restart.
     */
    public function reload(): void;

    /**
     * @return array<string, Skill> id => skill
     */
    public function all(): array;

    /**
     * Ids and descriptions. Any one keyword matches the id or the description.
     *
     * @param list<string> $keywords Empty lists every skill.
     *
     * @return list<array{id: string, description: string}>
     */
    public function list(array $keywords = []): array;
}
