<?php

declare(strict_types=1);

namespace Switon\Agency\Exception;

use Switon\Agency\Exception as AgentException;

/** Someone called {@see \Switon\Agency\Agent::cancel()} while the run was in flight. */
class RunCancelledException extends AgentException
{
    public function __construct()
    {
        parent::__construct('Agent run cancelled.');
    }

    public function interruptMessage(): string
    {
        return 'The user stopped the run.';
    }
}
