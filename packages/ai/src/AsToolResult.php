<?php

declare(strict_types=1);

namespace Switon\Ai;

use Switon\Ai\Part\ContentPart;

/**
 * {@see ToolResult} from a sync agent-as-tool run: worker `$call` plus a collapsed child `$turn`.
 *
 * Guidance: `$turn` carries only counters and the final completion, while the child transcript
 * stays on the child thread.
 *
 * @see \Switon\Ai\AsToolCall
 * @see \Switon\Agency\Toolkit\AsToolToolkit
 */
class AsToolResult extends ToolResult
{
    /**
     * @param array<string, mixed> $attributes
     * @param list<ContentPart> $parts
     */
    public function __construct(
        public AsToolCall $call,
        public Turn       $turn,
        mixed             $content = '',
        string            $id = '',
        string            $name = '',
        ?ToolTiming       $timing = null,
        array             $attributes = [],
        array             $parts = [],
    ) {
        parent::__construct($content, $id, $name, $timing, $attributes, $parts);
    }

    /**
     * Bind the host call id/name to a completed worker run.
     *
     * @param array<string, mixed> $attributes
     * @param list<ContentPart> $parts
     */
    public static function from(
        AsToolCall  $call,
        Turn        $turn,
        mixed       $content = '',
        ?ToolTiming $timing = null,
        array       $attributes = [],
        array       $parts = [],
    ): static {
        return new static(
            $call,
            $turn,
            $content,
            $call->id,
            $call->name,
            $timing,
            $attributes,
            $parts,
        );
    }

    public function __clone(): void
    {
        parent::__clone();
        $this->call = clone $this->call;
        $this->turn = clone $this->turn;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function decode(array $data): static
    {
        if ($data['timing'] !== null) {
            $data['timing'] = ToolTiming::decode($data['timing']);
        }

        $data['parts'] = self::decodeParts($data['parts']);
        $data['call'] = AsToolCall::decode($data['call']);
        $data['turn'] = Turn::decode($data['turn']);

        $result = new static($data['call'], $data['turn']);
        foreach ($data as $key => $value) {
            if (!property_exists($result, $key)) {
                continue;
            }
            $result->$key = $value;
        }

        return $result;
    }
}
