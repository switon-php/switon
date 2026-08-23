<?php

declare(strict_types=1);

namespace Switon\Skill;

use Switon\Ai\AgentInterface;
use Switon\Ai\Profile;
use Switon\Core\Attribute\Autowired;
use Switon\Core\FilesystemInterface;
use Switon\Yaml\FrontmatterReaderInterface;
use Throwable;

use function basename;
use function dirname;
use function explode;
use function is_string;
use function ksort;
use function rtrim;
use function str_contains;
use function str_starts_with;
use function stripos;
use function trim;

use const SORT_STRING;

/**
 * Loads {@see Skill} dirs from flat `$paths`.
 *
 * Guidance: Id is frontmatter `id`, or the directory basename when `id` is absent, blank, or not a string.
 * Desk packages publish their own rows through {@see set()}, not through this scan.
 * Later paths override the same id. {@see reload()} merges; it does not drop rows missing from disk.
 * {@see variables()} advertises {@see Profile::$skills} for the agent's profile.
 */
class Skills implements SkillsInterface
{
    #[Autowired] protected FilesystemInterface $filesystem;
    #[Autowired] protected FrontmatterReaderInterface $frontmatterReader;

    /**
     * Directories that contain skill subdirectories (alias or path; later overrides same id).
     *
     * @var list<string>
     */
    #[Autowired] protected array $paths = [
        '@switon/skill/resources/skills',
        '@root/skills',
        // Agent user catalogs: ~/.cursor/skills, ~/.codex/skills, ~/.switon/skills, …
        // (not ~/.cursor/skills-cursor). Glob: $path/*/SKILL.md.
        '~/.*/skills',
    ];

    /** @var array<string, Skill> */
    protected array $skills = [];

    /** @var array<string, Skill> skill directory → last disk row (for mtime skip) */
    protected array $byPath = [];

    public function get(string $id): Skill
    {
        if (!isset($this->skills[$id])) {
            Exception::raise('Agent skill "{id}" not found.', ['id' => $id]);
        }

        return $this->skills[$id];
    }

    public function set(Skill $skill): void
    {
        $this->skills[$skill->id] = $skill;
    }

    public function load(string $dir): ?Skill
    {
        $dir = rtrim($dir, '/');
        $file = $dir . '/SKILL.md';
        try {
            $doc = $this->frontmatterReader->read($file);
        } catch (Throwable) {
            // Foreign SKILL.md frontmatter may use YAML this reader rejects — skip the row.
            return null;
        }
        if ($doc === null) {
            return null;
        }

        /** @var array<string, mixed> $meta */
        $meta = $doc->meta;

        // Frontmatter is user input: a YAML number or array id would reach the string constructor as
        // a TypeError, and a blank id would register a row nothing can address.
        $id = $meta['id'] ?? null;
        if (!is_string($id) || trim($id) === '') {
            $id = basename($dir);
        }

        $description = $meta['description'] ?? null;

        return Skill::of([
            'id' => $id,
            'description' => is_string($description) ? $description : '',
            'instructions' => $doc->body,
            'path' => $dir,
            'mtime' => $this->filesystem->mtime($file) ?? 0,
        ]);
    }

    public function reload(): void
    {
        foreach ($this->paths as $path) {
            foreach ($this->filesystem->glob($path . '/*/SKILL.md') as $file) {
                $dir = rtrim(dirname($file), '/');
                $mtime = $this->filesystem->mtime($file) ?? 0;
                $prev = $this->byPath[$dir] ?? null;
                if ($prev !== null && $prev->mtime === $mtime) {
                    $this->skills[$prev->id] = $prev;

                    continue;
                }
                $skill = $this->load($dir);
                if ($skill === null) {
                    continue;
                }
                $this->byPath[$dir] = $skill;
                $this->skills[$skill->id] = $skill;
            }
        }
    }

    public function has(string $id): bool
    {
        return isset($this->skills[$id]);
    }

    public function block(string $id): string
    {
        if (!$this->has($id)) {
            return '[skill:' . $id . "]\n\nUnknown skill id: " . $id;
        }

        $skill = $this->get($id);

        return '[skill:' . $id . "]\n\nSkill path: " . $skill->path . "\n\n" . $skill->instructions;
    }

    public function all(): array
    {
        return $this->skills;
    }

    public function list(array $keywords = []): array
    {
        $skills = $this->skills;
        ksort($skills, SORT_STRING);

        $rows = [];
        foreach ($skills as $skill) {
            if ($keywords !== [] && !$this->matches($skill, $keywords)) {
                continue;
            }
            $rows[] = [
                'id' => $skill->id,
                'description' => $skill->description,
            ];
        }

        return $rows;
    }

    /**
     * @param list<string> $keywords
     */
    protected function matches(Skill $skill, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (stripos($skill->id, $keyword) !== false || stripos($skill->description, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{skills: list<array{id: string, description: string}>}
     */
    public function variables(AgentInterface $agent): array
    {
        $profile = $agent->profile();
        $skills = $this->skills;
        ksort($skills, SORT_STRING);

        $rows = [];
        foreach ($skills as $id => $skill) {
            $rows[] = [
                'id' => $id,
                'description' => $skill->description,
            ];
        }

        $byId = [];
        foreach ($rows as $entry) {
            $byId[$entry['id']] = $entry;
        }

        $listed = [];
        $seen = [];
        $append = static function (string $skillId) use (&$listed, &$seen, $byId, $profile): void {
            if (!isset($byId[$skillId])) {
                Exception::raise('Profile "{id}" references unknown skill "{skill}".', [
                    'id' => $profile->id,
                    'skill' => $skillId,
                ]);
            }
            if (!isset($seen[$skillId])) {
                $seen[$skillId] = true;
                $listed[] = $byId[$skillId];
            }
        };

        foreach ($profile->skills ?? [] as $skillId) {
            if (!is_string($skillId)) {
                Exception::raise('Profile "{id}" has a non-string skill id.', ['id' => $profile->id]);
            }
            $skillId = trim($skillId);
            if ($skillId === '') {
                continue;
            }
            $append($skillId);
        }

        // Explicit empty list opts out of dotted auto-prefix; null / non-empty still get it.
        if ($profile->skills !== [] && str_contains($profile->id, '.')) {
            $prefix = explode('.', $profile->id, 2)[0] . '.';
            if ($prefix !== '.') {
                foreach ($rows as $entry) {
                    if (str_starts_with($entry['id'], $prefix)) {
                        $append($entry['id']);
                    }
                }
            }
        }

        return ['skills' => $listed];
    }
}
