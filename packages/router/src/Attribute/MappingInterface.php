<?php

declare(strict_types=1);

namespace Switon\Routing\Attribute;

/**
 * Defines the contract for route mapping attributes discovered by the router discovery layer.
 *
 * Use when building custom attributes that provide a path and an HTTP verb for route registration.
 *
 * Guidance: This interface is the action marker for automatic route- and permission-scanning.
 * Manual routes can skip it; automatic scanning only sees methods with a mapping attribute.
 *
 * Path semantics are defined by the concrete base class <code>Mapping</code>.
 *
 * @see \Switon\Authorizing\Permissions::scanCatalog()
 */
interface MappingInterface
{
    /**
     * Returns the path configuration from the attribute.
     *
     * @return string|array<string>|null
     *
     * @see \Switon\Routing\Attribute\Mapping
     */
    public function getPath(): string|array|null;

    /**
     * Returns the HTTP verb for this mapping.
     *
     * Return <code>*</code> to match all HTTP methods.
     */
    public function getVerb(): string;
}
