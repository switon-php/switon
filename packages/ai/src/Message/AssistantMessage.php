<?php

declare(strict_types=1);

namespace Switon\Ai\Message;

use Switon\Ai\Part\ContentPart;
use Switon\Ai\ToolCall;

/** Assistant reply: prose on `$content`; typed blocks such as refusal live in `$parts`. */
class AssistantMessage extends \Switon\Ai\Message
{
    public const string Role = 'assistant';

    /**
     * @param list<ToolCall> $toolCalls
     * @param ?string $reasoning Provider reasoning trace; not ordinary reply content.
     * @param array<string, mixed> $passback Provider round-trip fields merged back into the next wire request.
     * @param list<ContentPart> $parts Typed blocks (e.g. {@see \Switon\Ai\Part\RefusalPart}); not ordinary prose.
     */
    public function __construct(
        public ?string $content = '',
        public array   $toolCalls = [],
        public ?string $reasoning = null,
        public array   $passback = [],
        public array   $parts = [],
    ) {
        parent::__construct(self::Role);
    }

    /**
     * Text contribution only; empty/null `$content` → no blocks (`$toolCalls` / parts stay off this path).
     *
     * @return list<array{type: 'text', text: string}>
     */
    public function wire(): array
    {
        if ($this->content === null || $this->content === '') {
            return [];
        }

        return [['type' => 'text', 'text' => $this->content]];
    }

    public function __clone(): void
    {
        $toolCalls = [];
        foreach ($this->toolCalls as $toolCall) {
            $toolCalls[] = clone $toolCall;
        }
        $this->toolCalls = $toolCalls;

        $parts = [];
        foreach ($this->parts as $part) {
            $parts[] = clone $part;
        }
        $this->parts = $parts;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function decode(array $data): static
    {
        $toolCalls = [];
        foreach ($data['toolCalls'] as $item) {
            /** @var class-string<ToolCall> $class */
            $class = $item['class'];
            $toolCalls[] = $class::decode($item);
        }
        $data['toolCalls'] = $toolCalls;

        $data['parts'] = self::decodeParts($data['parts']);

        return parent::decode($data);
    }
}
