<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Fixtures;

use Switon\Tooling\AbstractTool;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;

class StubTool extends AbstractTool
{
    public function __construct(
        protected string $name = '',
        protected string $description = '',
        protected mixed $handler = null,
        protected bool $readonly = false,
    ) {
    }

    /**
     * @return array{class: class-string<self>, name: string, description: string, handler: mixed, readonly: bool}
     */
    public static function config(
        string $name,
        string $description = '',
        mixed $handler = null,
        bool $readonly = false,
    ): array {
        return [
            'class' => self::class,
            'name' => $name,
            'description' => $description,
            'handler' => $handler,
            'readonly' => $readonly,
        ];
    }

    public function schema(string $name): ToolSchema
    {
        return new ToolSchema($this, description: $this->description);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function isReadonly(string $name, array $arguments): bool
    {
        unset($name, $arguments);

        return $this->readonly;
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        if ($this->handler === null) {
            // Echo model args only — never persist runtime bindings (.agent / .turn / …).
            $out = $arguments;
            foreach ($out as $key => $value) {
                if (is_string($key) && str_starts_with($key, '.') && is_object($value)) {
                    unset($out[$key]);
                }
            }

            return $out;
        }

        return ($this->handler)($arguments);
    }
}
