<?php

declare(strict_types=1);

namespace Switon\Ai\Message;

use Switon\Ai\Part\PinnedPart;

/**
 * Collapsed older history: the head row standing in for turns the model can no longer see.
 *
 * Wire role is {@see UserMessage::Role} — it sits on the user side of the history it replaces.
 * Not part of {@see \Switon\Ai\Charter} / stable system prompt.
 *
 * Guidance: `$content` is the excerpt. `$parts` are {@see PinnedPart} blocks carried after it;
 * their `$id` stays off the wire. A producer with structured rows subclasses this
 * (see `Switon\Thread\ExcerptMessage`) and owns the excerpt shape. The rewriting component owns
 * the invariant — once history is rewritten, the first turn is a fold head, and every rewrite
 * path carries it through untouched.
 */
class FoldedMessage extends \Switon\Ai\Message
{
    public const string Role = UserMessage::Role;

    /**
     * @param list<PinnedPart> $parts
     */
    public function __construct(
        protected string $content = '',
        public array     $parts = [],
    ) {
        parent::__construct(self::Role);
    }

    public static function of(string $content): static
    {
        return new static($content);
    }

    /**
     * Excerpt text, then each non-empty pinned body. `$id` is not a wire field.
     *
     * @return list<array{type: 'text', text: string}>
     */
    public function wire(): array
    {
        $blocks = $this->content === '' ? [] : [['type' => 'text', 'text' => $this->content]];
        foreach ($this->parts as $part) {
            if ($part->text !== '') {
                $blocks[] = ['type' => 'text', 'text' => $part->text];
            }
        }

        return $blocks;
    }

    /** Reader text is the excerpt. Pinned bodies stay on {@see self::wire()}. */
    public function text(): ?string
    {
        return $this->content === '' ? null : $this->content;
    }
}
