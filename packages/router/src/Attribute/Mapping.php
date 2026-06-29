<?php

declare(strict_types=1);

namespace Switon\Routing\Attribute;

/**
 * Base implementation for mapping attributes.
 *
 * Use when creating method or class attributes that store route paths and expose an HTTP verb.
 * Read this once: the path rules below apply to all `*Mapping` attributes.
 *
 * Path rules:
 * - class-level <code>RequestMapping(null)</code>: <code>/{controller-name}</code> (kebab-case, without <code>Controller</code>)
 * - method-level `*Mapping(null)`: <code>/{method-name}</code> under class prefix (kebab-case, without <code>Action</code>)
 * - <code>''</code>: class prefix only
 * - <code>'segment'</code>: append segment to class prefix
 * - <code>'/absolute'</code>: absolute path, ignore class prefix
 * - <code>['a', 'b']</code>: register one route per item
 *
 * Quick example (class prefix <code>/api/user</code>):
 * - <code>null</code> on <code>showAction</code> → <code>/api/user/show</code>
 * - <code>''</code> → <code>/api/user</code>
 * - <code>'{id}'</code> → <code>/api/user/{id}</code>
 * - <code>'/health'</code> → <code>/health</code>
 * - <code>['a', 'b']</code> → <code>/api/user/a</code> and <code>/api/user/b</code>
 *
 * @see \Switon\Routing\MappingPathInterface::resolveMethodPattern()
 * @see \Switon\Routing\MappingPathInterface::resolveControllerPrefixes()
 * @see \Switon\Routing\RouteRegistrarInterface::register()
 * @see \Switon\Http\ControllerMetadata::getActions()
 * @see \Switon\Core\Naming
 */
abstract class Mapping implements MappingInterface
{
    /**
     * @param string|array<string>|null $path Route path configuration.
     */
    public function __construct(protected string|array|null $path = null)
    {
    }

    public function getPath(): string|array|null
    {
        return $this->path;
    }
}
