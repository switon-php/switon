<?php

declare(strict_types=1);

namespace Switon\Routing\Exception;

use Switon\Routing\Exception;

/**
 * Reserved exception type for implementations that require a URI to resolve dynamic prefixes.
 *
 * Default <code>Router::getPrefix()</code> does not throw this exception; omitting the URI returns the configured prefix string.
 *
 * @see \Switon\Routing\RouterInterface::getPrefix()
 * @see \Switon\Routing\Router::getPrefix()
 */
class DynamicPrefixRequiresUriException extends Exception
{
}
