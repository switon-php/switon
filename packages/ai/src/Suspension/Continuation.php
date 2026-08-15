<?php

declare(strict_types=1);

namespace Switon\Ai\Suspension;

use Switon\Ai\AgentInterface;
use Switon\Ai\Letter;

/**
 * Inbox control letter: resumes a suspended turn; never transcript, never on the wire.
 *
 * Deliver via {@see AgentInterface::deliver()}; the host routes the letter. The host checks
 * {@see self::pairsWith()} before {@see Interrupt::resume()}.
 *
 * @see ToolReplyContinuation
 * @see ToolReviewContinuation
 * @see RetryContinuation
 * @see \Switon\Ai\DiscardTurnLetter
 */
abstract class Continuation implements Letter
{
    /**
     * Interrupt class this letter resumes. The host rejects a mismatch before {@see Interrupt::resume()}.
     *
     * @return class-string<Interrupt>
     */
    abstract public function pairsWith(): string;
}
