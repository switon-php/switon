<?php

declare(strict_types=1);

namespace Switon\Thread;

use Switon\Ai\Message\FoldedMessage;

use function implode;
use function trim;

/**
 * Fold head: the history row standing in for turns that left the working context.
 *
 * Whole rungs before the kept tail land here as `role` + `text` lines. The tail stays in its
 * own turns. Without this head the conversation would just start later, with nothing saying so.
 *
 * `$items` holds the surviving `user` / `asst` / `signal` rows only. The header is not a row: it is
 * generated from `$dropped`, so it can never contradict the flag.
 */
class ExcerptMessage extends FoldedMessage
{
    /** Stated once at the head: what is below is a shortened stand-in, with how to get the rest. */
    public const string HEADER = 'Earlier turns were dropped from the working context to fit the window; '
        . 'what remains is a shortened form. Call `recall` (default file: transcript.md) for the full text.';

    /**
     * @param list<array{role: 'user'|'asst'|'signal', text: string}> $items
     * @param bool $dropped Whole turns left the working context (not merely clamped).
     *                      Monotonic — once true, a later rewrite never clears it.
     * @param list<\Switon\Ai\Part\PinnedPart> $parts
     */
    public function __construct(public array $items = [], public bool $dropped = true, array $parts = [])
    {
        $lines = [];
        if ($dropped) {
            $lines[] = self::HEADER;
        }
        foreach ($items as $item) {
            $text = trim($item['text']);
            if ($text !== '') {
                // `role:` keeps a folded exchange distinguishable from the ask that follows it.
                $lines[] = $item['role'] . ': ' . $text;
            }
        }

        parent::__construct(implode("\n", $lines), $parts);
    }

    /**
     * Build from the stored rows directly: {@see Message::decode()} would `new static()` first and
     * assign `items` afterwards, leaving the composed body stale. Storage rows are our own
     * `jsonSerialize()` output, so they are taken as-is.
     *
     * @param array<string, mixed> $data
     */
    public static function decode(array $data): static
    {
        $parts = isset($data['parts']) ? self::decodeParts($data['parts']) : [];

        return new static($data['items'], $data['dropped'], $parts);
    }

    /** `$content` is composed from `$items` and `$dropped` — derived, never stored. */
    public function jsonSerialize(): array
    {
        $data = parent::jsonSerialize();
        unset($data['content']);

        return $data;
    }
}
