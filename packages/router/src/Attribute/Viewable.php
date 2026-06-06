<?php

declare(strict_types=1);

namespace Switon\Routing\Attribute;

/**
 * Marker interface for mappings that also support view rendering routes.
 *
 * Routing behavior: a viewable mapping ensures a GET route exists for the same pattern, in addition to the
 * mapping's primary verb route.
 *
 * Example with class prefix <code>/user</code>:
 * - <code>#[ViewPutMapping('{id}')]</code> → <code>GET /user/{id}</code> and <code>PUT /user/{id}</code>
 * - <code>#[ViewPostMapping('create')]</code> → <code>GET /user/create</code> and <code>POST /user/create</code>
 *
 * @see \Switon\Routing\Attribute\ViewGetMapping
 * @see \Switon\Routing\Attribute\ViewPutMapping
 * @see \Switon\Routing\RouteRegistrarInterface
 * @see \Switon\Routing\RouteRegistrar
 * @see \Switon\Http\RequestHandler::hasViewable()
 */
interface Viewable
{
}
