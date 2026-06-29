<?php

declare(strict_types=1);

namespace Switon\Routing\Attribute;

use Attribute;

/**
 * Defines controller route prefixes for all HTTP methods.
 *
 * Road-signs:
 * - `*Controller` `#[RequestMapping]`
 * - `#[GetMapping]` `#[PostMapping]` `#[ViewGetMapping]`
 * - `Mapping` `null` `''` `'segment'` `'/absolute'` `['a','b']`
 * - `MappingDiscovery::discover()` `ClassScanner`
 * - `RouterInterface::add*()` `match()`
 *
 * Method usage: apply it to an action when that action must accept all HTTP methods; path argument semantics follow
 * <code>Mapping</code>.
 *
 * Controller usage:
 * - marks a controller for discovery
 * - defines the route prefix used by action mappings
 * - without <code>RequestMapping</code> on the controller, discovery excludes that controller
 *
 * Value behavior:
 * - <code>#[RequestMapping]</code> or <code>#[RequestMapping(null)]</code> → auto prefix from controller name
 * - <code>#[RequestMapping('')]</code> → controller discovered with no prefix
 * - <code>#[RequestMapping('/api/user')]</code> or <code>#[RequestMapping(['/v1/user', '/v2/user'])]</code> → explicit prefix(es)
 *
 * When the value is <code>null</code> (or omitted), the framework derives the prefix from the controller name in
 * <code>kebab-case</code>.
 *
 * Derived prefix examples:
 * - <code>UserController</code> → <code>/user</code>
 * - <code>UserProfileController</code> → <code>/user-profile</code>
 * - <code>IndexController</code> → <code>/index</code>
 *
 * Minimal controller example:
 * <code>
 * #[RequestMapping('/api/user')]
 * class UserController
 * {
 *     #[GetMapping] public function listAction(): array {}
 *     #[PostMapping('')] public function createAction(): array {}
 * }
 * </code>
 *
 * @see \Switon\Routing\Attribute\Mapping
 * @see \Switon\Routing\MappingPathInterface
 * @see \Switon\Routing\MappingPathInterface::resolveMethodPattern()
 * @see \Switon\Routing\MappingPathInterface::resolveControllerPrefixes()
 * @see \Switon\Http\ControllerMetadata
 * @see \Switon\Http\ControllerMetadata::getActions()
 * @see \Switon\Http\ControllerMetadata::getPath()
 * @see \Switon\Routing\RouterInterface
 * @see \Switon\Routing\RouterInterface::add()
 * @see \Switon\Routing\RouterInterface::match()
 * @see \Switon\Routing\RouteRegistrarInterface
 * @see \Switon\Routing\RouteRegistrarInterface::register()
 * @see \Switon\Routing\RouteRegistrarInterface::registerClass()
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class RequestMapping extends Mapping
{
    /**
     * Returns wildcard method support (<code>*</code>).
     */
    public function getVerb(): string
    {
        return '*';
    }
}
