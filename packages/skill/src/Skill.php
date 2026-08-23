<?php

declare(strict_types=1);

namespace Switon\Skill;

/**
 * One catalog row: id, description, instructions, and the directory they came from.
 *
 * Guidance: `$path` is the skill directory that holds `SKILL.md` — a skill is a directory unit,
 * like a desk. `$mtime` is the `SKILL.md` mtime used by {@see Skills::reload()} to skip a re-parse.
 */
class Skill
{
    public function __construct(
        public string $id = '',
        public string $description = '',
        public string $instructions = '',
        public string $path = '',
        public int    $mtime = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function of(array $data): static
    {
        return new static(
            id: $data['id'] ?? '',
            description: $data['description'] ?? '',
            instructions: $data['instructions'] ?? '',
            path: $data['path'] ?? '',
            mtime: (int)($data['mtime'] ?? 0),
        );
    }
}
