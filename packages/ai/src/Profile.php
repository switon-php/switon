<?php

declare(strict_types=1);

namespace Switon\Ai;

use Switon\Core\Naming;

/**
 * Catalog profile: identity, own prompt/tool grant, and shared segment references.
 * `$prompt` stays template text until assembly produces {@see Charter}.
 *
 * Road-signs:
 * - Source: `@agents` → app → user overlay ({@see \Switon\Agency\ProfilesInterface})
 * - Assembly/render: {@see PromptRendererInterface}
 * - As-tool mount: non-empty `$asTool` is the complete wire id
 *   ({@see \Switon\Agency\Toolkit\AsToolToolkit})
 */
class Profile
{
    /**
     * @param string $prompt Own body, template text
     * @param list<string> $tools Own tool grant
     * @param list<string> $segments Shared segment ids merged at charter assembly
     * @param list<string>|null $skills Prompt-advertised skill ids — `null` = undeclared (dotted
     *        ids auto-advertise `{prefix}.*`); `[]` = none advertised (UI / skill_load still allowed);
     *        non-empty = those ids plus dotted auto-prefix
     */
    public function __construct(
        public string  $id = '',
        public string  $description = '',
        public string  $model = '',
        public string  $prompt = '',
        public array   $tools = [],
        public array   $segments = [],
        public ?array  $skills = null,
        /** Model-step budget for one turn; null = catalog default resolved at `Profiles::get()`. */
        public ?int    $maxSteps = null,
        /** As-tool wire name (`agent_web_research`). Empty = not mounted. */
        public string  $asTool = '',
        /** Host/session picker catalog; does not disable runtime use. */
        public bool    $selectable = true,
        /** UI picker glyph: empty monogram, emoji, or Lucide kebab name. */
        public string  $icon = '',
        /** Picker order. Larger values sort earlier; default 0; negative values sort later. */
        public int     $order = 0,
        /** Source markdown file; set by the loader, never by frontmatter. Empty for synthesized rows. */
        public string  $path = '',
    ) {
    }

    /**
     * Decode a markdown row: frontmatter map with the body under `prompt` and the source file under `path`.
     *
     * Guidance: camelize snake_case keys and resolve segment/skill references at assembly.
     *
     * @param array<string, mixed> $data
     */
    public static function decode(array $data): static
    {
        $profile = new static();
        foreach (Naming::camelKeys($data) as $key => $value) {
            if (property_exists($profile, $key)) {
                $profile->$key = $value;
            }
        }

        return $profile;
    }
}
