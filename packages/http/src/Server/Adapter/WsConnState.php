<?php

declare(strict_types=1);

namespace Switon\Http\Server\Adapter;

use Switon\Core\ContextConnScoped;
use Switon\Core\Runtime as CoreRuntime;
use Swoole\Coroutine;

/**
 * Per-fd WebSocket transport state: open ordering, coroutine waiters, conn-scoped context snapshots.
 *
 * Distinct from {@see \Switon\Http\Ws\ConnContext} (application route binding after handshake).
 */
class WsConnState
{
    public bool $openReady = false;

    /** @var list<int> */
    public array $pendingMessageCids = [];

    public ?int $pendingCloseCid = null;

    /** @var array<int, ContextConnScoped> */
    public array $contextSnapshots = [];

    public function finishOpen(): void
    {
        $this->mergeConnContexts();
        $this->openReady = true;

        foreach ($this->pendingMessageCids as $cid) {
            Coroutine::resume($cid);
        }
        $this->pendingMessageCids = [];

        if ($this->pendingCloseCid !== null) {
            Coroutine::resume($this->pendingCloseCid);
            $this->pendingCloseCid = null;
        }
    }

    public function waitForOpenMessage(): void
    {
        if ($this->openReady) {
            return;
        }

        if (!CoreRuntime::isCoroutineEnabled() || Coroutine::getCid() <= 0) {
            return;
        }

        $this->pendingMessageCids[] = Coroutine::getCid();
        Coroutine::suspend();
    }

    public function waitForOpenClose(): bool
    {
        if ($this->openReady) {
            return true;
        }

        if (!CoreRuntime::isCoroutineEnabled() || Coroutine::getCid() <= 0) {
            return false;
        }

        $this->pendingCloseCid = Coroutine::getCid();
        Coroutine::suspend();

        return $this->openReady;
    }

    public function restoreConnContexts(): void
    {
        if ($this->contextSnapshots === [] || !extension_loaded('swoole')) {
            return;
        }

        $current = Coroutine::getContext();
        if ($current === null) {
            return;
        }

        foreach ($this->contextSnapshots as $objectId => $context) {
            $current[$objectId] = $context;
        }
    }

    public function mergeConnContexts(): void
    {
        if (!CoreRuntime::isCoroutineEnabled() || Coroutine::getCid() <= 0) {
            return;
        }

        $current = Coroutine::getContext();
        if ($current === null) {
            return;
        }

        foreach ($current as $objectId => $context) {
            if ($context instanceof ContextConnScoped) {
                $this->contextSnapshots[$objectId] = $context;
            }
        }
    }
}
