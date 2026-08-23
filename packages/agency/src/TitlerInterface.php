<?php

declare(strict_types=1);

namespace Switon\Agency;

use Switon\Ai\Thread;

/** One-shot LLM sidebar title (`titler.md`). */
interface TitlerInterface
{
    public function title(Thread $thread): string;
}
