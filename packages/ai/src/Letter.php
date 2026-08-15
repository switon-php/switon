<?php

declare(strict_types=1);

namespace Switon\Ai;

/**
 * Anything sealed into an {@see Envelope} and delivered to an agent inbox.
 *
 * Two kinds of letter share the envelope:
 * - {@see Message} — chat content the model reads (user / assistant / tool / signal rows).
 * - {@see Suspension\Continuation} — control mail that drives the state machine; never on the wire.
 *
 * Guidance: an envelope carries exactly one letter; read the concrete type when it matters.
 */
interface Letter
{
}
