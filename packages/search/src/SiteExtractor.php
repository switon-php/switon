<?php

declare(strict_types=1);

namespace Switon\Search;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use JsonException;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Strings;

use function html_entity_decode;
use function strip_tags;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * Reads one site response into rows from the site file's `item` spec.
 *
 * @see \Switon\Search\SiteExtractorInterface
 */
class SiteExtractor implements SiteExtractorInterface
{
    #[Autowired] protected CssToXPath $css;

    /**
     * @return list<array<string, string>>
     */
    public function extract(Site $site, string $document, string $documentUrl): array
    {
        if (!$site->clean) {
            Exception::raise('Site {id} does not clean the response', ['id' => $site->id]);
        }

        if ($site->kind === 'json') {
            return $this->jsonHits($site, $document, $documentUrl);
        }

        return $this->htmlHits($site, $document, $documentUrl);
    }

    /**
     * @return list<array<string, string>>
     */
    protected function jsonHits(Site $site, string $document, string $documentUrl): array
    {
        $decoded = $this->decode($site, $document);

        $list = $this->jsonGet($decoded, $site->each);
        if (!is_array($list) || !array_is_list($list)) {
            return [];
        }

        $hits = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $hit = $this->hit($site, $item, function (string $path) use ($item): mixed {
                return $this->jsonGet($item, $path);
            }, $documentUrl);
            if ($hit !== null) {
                $hits[] = $hit;
            }
        }

        return $hits;
    }

    /** JSON body. A `jsonp` name unwraps `cb({...});`; a bare body still decodes. */
    protected function decode(Site $site, string $document): mixed
    {
        $body = trim($document);
        if ($site->jsonp !== '') {
            $pattern = '/\A' . preg_quote($site->jsonp, '/') . '\s*\(\s*(.*?)\s*\)\s*;?\z/s';
            if (preg_match($pattern, $body, $match) === 1) {
                $body = $match[1];
            }
        }

        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Exception::raise('Site {id} JSON document is invalid: {message}', [
                'id' => $site->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return list<array<string, string>>
     */
    protected function htmlHits(Site $site, string $document, string $documentUrl): array
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $document, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($loaded === false) {
            return [];
        }

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query($this->css->transform($site->each, false));
        if ($nodes === false) {
            Exception::raise('Site {id} each selector is invalid', ['id' => $site->id]);
        }

        $hits = [];
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            // An HTML node has no field set of its own, so no `item` means its text.
            $row = $site->item === [] ? ['title' => $node->textContent] : [];
            $hit = $this->hit($site, $row, function (string $path) use ($xpath, $node): mixed {
                return $this->htmlGet($xpath, $node, $path);
            }, $documentUrl);
            if ($hit !== null) {
                $hits[] = $hit;
            }
        }

        return $hits;
    }

    /**
     * One row. No `item` passes the source row through. A field that misses is left out.
     *
     * @param array<array-key, mixed> $row
     * @param callable(string): mixed $read
     *
     * @return array<array-key, mixed>|null
     */
    protected function hit(Site $site, array $row, callable $read, string $documentUrl): ?array
    {
        if ($site->item === []) {
            return $row === [] ? null : $row;
        }

        $record = [];
        foreach ($site->item as $name => $spec) {
            $value = $this->field($name, $spec, $read, $documentUrl);
            if ($value !== null) {
                $record[$name] = $value;
            }
        }

        return $record === [] ? null : $record;
    }

    /**
     * Resolve one field, or null when nothing resolves.
     *
     * @param list<mixed>|array<string, mixed> $spec
     * @param callable(string): mixed $read
     */
    protected function field(string $name, array $spec, callable $read, string $documentUrl): ?string
    {
        if (is_string($spec['by'] ?? null)) {
            $alternatives = $this->branch($spec, $read);
        } elseif (array_key_exists('value', $spec)) {
            $value = $spec['value'];
            $alternatives = is_array($value) ? array_values($value) : [$value];
        } else {
            $alternatives = $spec;
        }

        foreach ($alternatives as $alternative) {
            $value = $this->alternative($alternative, $name, $read);
            if ($value === null) {
                continue;
            }
            if ($name === 'url') {
                $value = $this->url($value, $this->base($spec, $documentUrl));
                if ($value === null) {
                    continue;
                }

                return (bool) ($spec['https'] ?? false) ? $this->secure($value) : $value;
            }

            return $this->text($value);
        }

        return null;
    }

    /**
     * `base` when set, otherwise the fetched document URL.
     *
     * @param array<int|string, mixed> $spec
     */
    protected function base(array $spec, string $documentUrl): string
    {
        $base = $spec['base'] ?? null;

        return is_string($base) && $base !== '' ? $base : $documentUrl;
    }

    /** `https: true` upgrades an `http://` link. */
    protected function secure(string $url): string
    {
        return stripos($url, 'http://') === 0
            ? 'https://' . substr($url, strlen('http://'))
            : $url;
    }

    /**
     * The alternatives a branch table selects for this item.
     *
     * @param array<int|string, mixed> $spec Field spec carrying `by`
     * @param callable(string): mixed $read
     *
     * @return list<mixed>
     */
    protected function branch(array $spec, callable $read): array
    {
        $key = $this->branchKey($read((string) $spec['by']));
        if ($key !== null && array_key_exists($key, $spec) && $key !== 'by') {
            $picked = $spec[$key];

            return is_array($picked) ? array_values($picked) : [$picked];
        }
        if (array_key_exists('_', $spec)) {
            $fallback = $spec['_'];

            return is_array($fallback) ? array_values($fallback) : [$fallback];
        }

        return [];
    }

    /** A branch key as written in YAML: scalars compare as strings, anything else has no branch. */
    protected function branchKey(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /** @param callable(string): mixed $read */
    protected function alternative(mixed $alternative, string $field, callable $read): ?string
    {
        if (!is_scalar($alternative)) {
            return null;
        }
        $alternative = (string) $alternative;

        // A template that is one whole placeholder reads one value: the value is already a
        // complete string, so it must pass through unchanged instead of being encoded and
        // treated as a fragment of a URL.
        if (preg_match('/\A\{([^{}]+)\}\z/', $alternative, $whole) === 1) {
            $alternative = trim($whole[1]);
        } elseif (str_contains($alternative, '{') && str_contains($alternative, '}')) {
            return $this->template($alternative, $field === 'url', $read);
        }

        $value = $read($alternative);
        if ($this->miss($value) || !is_scalar($value)) {
            return null;
        }

        return (string) $value;
    }

    /** @param callable(string): mixed $read */
    protected function template(string $template, bool $encode, callable $read): ?string
    {
        $missed = false;
        $rendered = preg_replace_callback(
            '/\{([^{}]+)\}/',
            function (array $match) use ($read, $encode, &$missed): string {
                $value = $read(trim($match[1]));
                if ($this->miss($value) || !is_scalar($value)) {
                    $missed = true;

                    return '';
                }

                $text = (string) $value;

                return $encode ? $this->encodePath($text) : $text;
            },
            $template,
        );
        if ($missed || !is_string($rendered)) {
            return null;
        }

        return $rendered;
    }

    /** Encode each path segment. Slashes stay. */
    protected function encodePath(string $value): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $value)));
    }

    protected function miss(mixed $value): bool
    {
        return $value === null
            || $value === ''
            || $value === 0
            || $value === 0.0
            || $value === false
            || is_array($value);
    }

    protected function text(string $value): string
    {
        // Decode first: `&lt;em&gt;` is a tag only after decoding, and stripping before that
        // would leave the markup visible as text.
        return Strings::squish(
            strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
        );
    }

    protected function url(string $value, string $documentUrl): ?string
    {
        $value = trim($value);
        if ($value === '' || str_starts_with($value, '#')) {
            return null;
        }
        if (str_starts_with(strtolower($value), 'javascript:')) {
            return null;
        }
        if (preg_match('#^([a-z][a-z0-9+.-]*):#i', $value, $scheme) === 1) {
            $name = strtolower($scheme[1]);

            return $name === 'http' || $name === 'https' ? $value : null;
        }

        return $this->resolve($value, $documentUrl);
    }

    protected function resolve(string $url, string $base): ?string
    {
        $parts = parse_url($base);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        if (str_starts_with($url, '//')) {
            return $parts['scheme'] . ':' . $url;
        }
        if (str_starts_with($url, '?')) {
            $path = is_string($parts['path'] ?? null) && $parts['path'] !== '' ? $parts['path'] : '/';

            return $origin . $path . $url;
        }
        if (str_starts_with($url, '/')) {
            return $origin . $url;
        }

        $path = is_string($parts['path'] ?? null) ? $parts['path'] : '/';
        $slash = strrpos($path, '/');
        $dir = $slash === false ? '/' : substr($path, 0, $slash + 1);

        return $origin . $dir . $url;
    }

    protected function jsonGet(mixed $node, string $path): mixed
    {
        foreach ($this->segments($path) as $name) {
            if (!is_array($node) || !array_key_exists($name, $node)) {
                return null;
            }
            $node = $node[$name];
        }

        return $node;
    }

    /** @return list<string> */
    protected function segments(string $path): array
    {
        if ($path === '.' || $path === '') {
            return [];
        }

        $segments = explode('.', $path);
        foreach ($segments as $name) {
            if (preg_match('/^[A-Za-z0-9_-]+$/', $name) !== 1) {
                Exception::raise('Invalid JSON path {path}', ['path' => $path]);
            }
        }

        return $segments;
    }

    protected function htmlGet(DOMXPath $xpath, DOMElement $item, string $path): ?string
    {
        [$selector, $attribute] = $this->splitAttribute($path);
        if ($selector === '') {
            $node = $item;
        } else {
            $nodes = $xpath->query($this->css->transform($selector, true), $item);
            if ($nodes === false || $nodes->length === 0) {
                return null;
            }
            $node = $nodes->item(0);
            if (!$node instanceof DOMNode) {
                return null;
            }
        }

        if ($attribute !== null) {
            if (!$node instanceof DOMElement || !$node->hasAttribute($attribute)) {
                return null;
            }

            return $node->getAttribute($attribute);
        }

        return $node->textContent;
    }

    /** @return array{0: string, 1: string|null} */
    protected function splitAttribute(string $path): array
    {
        $depth = 0;
        $at = null;
        $length = strlen($path);
        for ($i = 0; $i < $length; $i++) {
            $char = $path[$i];
            if ($char === '[') {
                $depth++;
            } elseif ($char === ']') {
                $depth = max(0, $depth - 1);
            } elseif ($char === '@' && $depth === 0) {
                $at = $i;
            }
        }
        if ($at === null) {
            return [trim($path), null];
        }

        return [trim(substr($path, 0, $at)), substr($path, $at + 1)];
    }
}
