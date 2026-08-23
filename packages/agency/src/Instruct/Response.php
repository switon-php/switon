<?php

declare(strict_types=1);

namespace Switon\Agency\Instruct;

/** Envelope constants for the terminal `response` tool. */
class Response
{
    /** Failure-envelope label. The live wire id is `tmp_instruct_{object_id}` on {@see \Switon\Agency\Instructor}. */
    public const string NAME = 'response';

    /** `code` value that closes a successful instruct. */
    public const string OK = 'ok';

    /** `code` values that mean the run produced a business-level failure rather than an answer. */
    public const array FAIL_CODES = [
        'insufficient_input',
        'blocked',
        'unsupported',
        'refused',
        'inconclusive',
        'other',
    ];
}
