<?php

declare(strict_types=1);

namespace Switon\Ai\Message;

class SystemMessage extends \Switon\Ai\Message
{
    public const string Role = 'system';

    public function __construct(public string $content = '')
    {
        parent::__construct(self::Role);
    }

    public static function of(string $content): static
    {
        return new static($content);
    }

    public function wire(): array
    {
        return [['type' => 'text', 'text' => $this->content]];
    }
}
