<?php

declare(strict_types=1);

namespace Switon\Agency;

use Switon\Ai\Segment;
use Switon\Core\Attribute\Autowired;
use Switon\Yaml\FrontmatterReaderInterface;

use function basename;
use function is_string;
use function trim;

/**
 * Default {@see SegmentsInterface}: an in-memory segment registry.
 *
 * Guidance: this class has no paths of its own — segments are part of the agent tree, so
 * {@see ProfileDiscovery} drives discovery and hands each file to {@see load()}. Desk
 * <code>agents/segments/</code> arrive the same way, through {@see \Switon\Desk\Desks::load()}.
 */
class Segments implements SegmentsInterface
{
    #[Autowired] protected FrontmatterReaderInterface $frontmatterReader;

    /** @var array<string, Segment> */
    protected array $segments = [];

    public function get(string $id): Segment
    {
        if (!isset($this->segments[$id])) {
            Exception::raise('Segment "{id}" not found.', ['id' => $id]);
        }

        return $this->segments[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->segments[$id]);
    }

    public function set(Segment $segment): void
    {
        $this->segments[$segment->id] = $segment;
    }

    public function all(): array
    {
        return $this->segments;
    }

    /** Load one markdown segment file; null when it has no readable frontmatter. */
    public function load(string $file): ?Segment
    {
        $doc = $this->frontmatterReader->read($file);
        if ($doc === null) {
            return null;
        }

        $meta = [];
        foreach ($doc->meta as $key => $value) {
            if (is_string($key)) {
                $meta[$key] = $value;
            }
        }

        $segment = Segment::decode([
            ...$meta,
            'prompt' => trim($doc->body),
        ]);
        $segment->id = $meta['id'] ?? basename($file, '.md');
        $segment->path = $file;

        return $segment;
    }
}
