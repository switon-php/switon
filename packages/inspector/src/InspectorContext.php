<?php

declare(strict_types=1);

namespace Switon\Inspector;

/**
 * Holds request-scoped inspector state for link generation and payload persistence.
 *
 * @see \Switon\Inspector\Inspector
 */
class InspectorContext
{
    /** Whether inspector is enabled for the current request. */
    public bool $enabled = false;
    /** Generated inspector storage key for the current request. */
    public string $key = '';
    /** Generated inspector URL for the current request. */
    public string $url = '';
}
