<?php

declare(strict_types=1);

namespace Switon\Ai;

use Switon\Core\Json;

use function implode;

/**
 * Abstract runtime signal envelope; wire form `[signal:<label>]`.
 *
 * Guidance: `$body` is the provider detail and the default reader line; override {@see self::text()}
 * when the reader line differs, and each concrete subclass must name `Role`.
 */
abstract class Signal extends Message
{
    public const string Role = '';

    /** @param array<string, scalar|null> $fields */
    public function __construct(
        public string $name = '',
        public array  $fields = [],
        public string $body = '',
    ) {
        parent::__construct(static::Role);
    }

    /**
     * Reader line: `[{label}]` plus {@see self::$body}. The `signal` role is added by the reader.
     * {@see self::wire()} stays the protocol envelope.
     */
    public function text(): ?string
    {
        $label = $this->label();

        return $this->body === '' ? "[$label]" : "[$label] {$this->body}";
    }

    public function label(): string
    {
        return $this->name;
    }

    public function wire(): array
    {
        $lines = ['[signal:' . $this->label() . ']'];
        foreach ($this->fields as $key => $value) {
            $lines[] = $key . ': ' . Json::text($value);
        }

        return [['type' => 'text', 'text' => implode("\n", $lines) . "\n\n" . $this->body]];
    }
}
