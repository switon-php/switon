<?php

declare(strict_types=1);

namespace Switon\Principal;

/**
 * Stores request-scoped identity state.
 *
 * Use as the mutable context behind <code>Identity</code> for the current request runtime.
 * Fields stay normalized to <code>id</code>, <code>name</code>, and role list only.
 *
 * @see \Switon\Principal\Identity
 */
class IdentityContext
{
    public int|string|null $id = null;

    public ?string $name = null;

    /** @var list<string> */
    public array $roles = [];
}
