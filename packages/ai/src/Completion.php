<?php

declare(strict_types=1);

namespace Switon\Ai;

use JsonSerializable;
use Switon\Ai\Message\AssistantMessage;
use Switon\Core\Strings;

use function property_exists;

/**
 * One persisted slot: `$input` plus optional `$output` (turn id/model live on {@see Turn}).
 *
 * @see \Switon\Ai\Thread
 */
class Completion implements JsonSerializable
{
    /** `$finishReason` when the caller aborted the completion. */
    public const string REASON_CANCEL = 'cancel';

    /**
     * @param ?string $finishReason Provider end reason, or {@see self::REASON_CANCEL} when the caller aborted.
     * @param array<string, mixed> $attributes App-only metadata; never on the provider wire.
     * @param int $step Slot position within the turn, starting at 1. Later rows use the previous step plus one.
     * @param ?Message $output Model reply (`AssistantMessage`) or framework signal
     *        (`AssistantSignal`); null = unset (hung tip or input-only row — not a hung flag).
     */
    public function __construct(
        public Message                       $input,
        public string                        $completeId = '',
        public ?string                       $finishReason = null,
        public ?TokenUsage                   $usage = null,
        public array                         $attributes = [],
        public int                           $step = 1,
        public float                         $landedAt = 0.0,
        public ?LlmTiming                    $timing = null,
        public ?Message                      $output = null,
    ) {
    }

    public static function of(Message $input, ?Message $output = null): static
    {
        return new static($input, Strings::uuid(), output: $output);
    }

    /**
     * @return list<Message> `$input`, then `$output` when it is set.
     */
    public function messages(): array
    {
        $messages = [$this->input];
        if ($this->output !== null) {
            $messages[] = $this->output;
        }

        return $messages;
    }

    /**
     * Tool calls requested by this slot's model reply; empty for signals, tool rows, and plain replies.
     *
     * @return list<ToolCall>
     */
    public function toolCalls(): array
    {
        return $this->output instanceof AssistantMessage ? $this->output->toolCalls : [];
    }

    /** Whether the caller aborted this completion. */
    public function isCancelled(): bool
    {
        return $this->finishReason === self::REASON_CANCEL;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }

    public function __clone(): void
    {
        $this->input = clone $this->input;
        if ($this->output !== null) {
            $this->output = clone $this->output;
        }
        if ($this->usage !== null) {
            $this->usage = clone $this->usage;
        }
        if ($this->timing !== null) {
            $this->timing = clone $this->timing;
        }
    }

    /**
     * Guidance: decodes trusted internal storage only.
     *
     * @param array<string, mixed> $data
     */
    public static function decode(array $data): static
    {
        /** @var class-string<Message> $class */
        $class = $data['input']['class'];
        $data['input'] = $class::decode($data['input']);

        if (null !== ($output = $data['output'])) {
            $data['output'] = $output['class']::decode($output);
        }

        if (null !== $data['usage']) {
            $data['usage'] = TokenUsage::decode($data['usage']);
        }

        if ($data['timing'] !== null) {
            $data['timing'] = LlmTiming::decode($data['timing']);
        }

        $completion = new static($data['input']);
        foreach ($data as $key => $value) {
            if (!property_exists($completion, $key)) {
                continue;
            }

            $completion->$key = $value;
        }

        return $completion;
    }
}
