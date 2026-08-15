<?php

declare(strict_types=1);

namespace Switon\Ai\Signal;

/** User-side stop row. */
class UserAbortSignal extends UserSignal
{
    public const string NAME = 'user_abort';

    /** @param array<string, scalar|null> $fields */
    public function __construct(
        array  $fields = [],
        string $body = '',
    ) {
        parent::__construct(self::NAME, $fields, $body);
    }
}
