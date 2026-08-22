<?php

declare(strict_types=1);

namespace Switon\Search;

use function array_is_list;
use function array_values;
use function is_array;

/** One site file. An empty `response` does not clean the document. Unknown keys are ignored. */
class Site
{
    /**
     * @param array<string, list<mixed>|array<string, mixed>> $item Field name to alternatives, or to a `by` table
     */
    public function __construct(
        public string $id = '',
        public string $description = '',
        public string $request = '',
        public string $ready = '',
        public string $kind = '',
        public bool $clean = false,
        public string $each = '',
        public array $item = [],
        public string $fetch = '',
        public string $referer = '',
        public int $size = 0,
        /** JSONP callback name; empty means the body is plain JSON. */
        public string $jsonp = '',
        /** Source yaml file; set by the loader, never by the document. */
        public string $path = '',
    ) {
    }

    /**
     * One site from one parsed YAML document.
     *
     * @param array<int|string, mixed> $meta Parsed site document
     */
    public static function of(array $meta, string $stem = ''): self
    {
        $site = new self();
        foreach ($meta as $key => $value) {
            if (is_string($key) && property_exists($site, $key)) {
                $site->$key = $value;
            }
        }
        if ($site->id === '') {
            $site->id = $stem;
        }

        $response = $meta['response'] ?? null;
        if (is_array($response) && $response !== [] && !array_is_list($response)) {
            $site->clean = true;
            $site->kind = ($response['kind'] ?? null) === 'json' ? 'json' : 'html';
            $site->each = $response['each'] ?? '';

            // A field names one path, a list tried in order, or a `by` table.
            foreach (is_array($response['item'] ?? null) ? $response['item'] : [] as $name => $paths) {
                $site->item[$name] = self::field($paths);
            }
        }

        return $site;
    }

    /**
     * Normalize one field spec: a table with `by` or `value` stays a map, anything else is a list.
     *
     * @return list<mixed>|array<string, mixed>
     */
    protected static function field(mixed $spec): array
    {
        if (!is_array($spec)) {
            return [$spec];
        }
        if (!array_is_list($spec) && (isset($spec['by']) || isset($spec['value']))) {
            return $spec;
        }

        return array_values($spec);
    }
}
