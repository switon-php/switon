<?php

declare(strict_types=1);

namespace Switon\Ai\Message;

use Switon\Ai\ToolResult;

class ToolMessage extends \Switon\Ai\Message
{
    public const string Role = 'tool';

    /**
     * @param list<ToolResult> $results
     */
    public function __construct(public array $results = [])
    {
        parent::__construct(self::Role);
    }

    /**
     * @param list<ToolResult> $results
     */
    public static function of(array $results): static
    {
        return new static($results);
    }

    /** Tool payloads live on `$results`, not content blocks. */
    public function wire(): array
    {
        return [];
    }

    public function __clone(): void
    {
        $cloned = [];
        foreach ($this->results as $result) {
            $cloned[] = clone $result;
        }
        $this->results = $cloned;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function decode(array $data): static
    {
        $results = [];
        foreach ($data['results'] as $item) {
            $results[] = $item['class']::decode($item);
        }
        $data['results'] = $results;

        return parent::decode($data);
    }
}
