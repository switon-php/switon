<?php

declare(strict_types=1);

namespace Switon\Langfuse\Tests\Fixtures;

use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\LlmInterface;
use Switon\Ai\Model;
use Switon\Ai\Thread;

/** @internal */
class StubLlm implements LlmInterface
{
    public function complete(Thread $thread, Charter $charter): Completion
    {
        return $thread->lastCompletion();
    }

    public function stream(
        Thread $thread,
        Charter $charter,
        ?callable $onDelta = null,
        ?callable $shouldAbort = null,
    ): Completion {
        return $this->complete($thread, $charter);
    }

    public function model(): Model
    {
        return Model::of(model: 'stub', window: 128_000);
    }

    public function window(): int
    {
        return 128_000;
    }




}
