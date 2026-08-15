<?php

declare(strict_types=1);

namespace Switon\Ai;

/**
 * One grant slice: optional prompt text and tool names.
 *
 * {@see \Switon\Agency\Charters} merges segments into {@see Charter}.
 *
 * @see \Switon\Ai\Profile
 */
class Segment
{
    /**
     * @param list<string> $tools Tool names to expose
     */
    public function __construct(
        public string $id = '',
        public string $prompt = '',
        public array  $tools = [],
        /** Source markdown file; set by the loader, never by frontmatter. */
        public string $path = '',
    ) {
    }

    /**
     * Decode a markdown row: segment frontmatter map with body under `prompt`.
     *
     * Guidance: tool union and dedupe happen at charter assembly.
     *
     * @param array<string, mixed> $data
     */
    public static function decode(array $data): static
    {
        $segment = new static();
        foreach ($data as $key => $value) {
            if (property_exists($segment, $key)) {
                $segment->$key = $value;
            }
        }

        return $segment;
    }
}
