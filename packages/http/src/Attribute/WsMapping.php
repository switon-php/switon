<?php

declare(strict_types=1);

namespace Switon\Http\Attribute;

use Switon\Routing\Attribute\Mapping;

/**
 * Base for WebSocket event mappings ({@see MappingInterface} with <code>WS_*</code> verbs).
 *
 * Path is always <code>''</code>: upgrade URL comes from class {@see \Switon\Routing\Attribute\RequestMapping}
 * only; event actions are not URL segments (unlike HTTP {@see Mapping} where <code>null</code> derives from the method name).
 */
abstract class WsMapping extends Mapping
{
    public const string VERB_OPEN = 'WS_OPEN';
    public const string VERB_MESSAGE = 'WS_MESSAGE';
    public const string VERB_CLOSE = 'WS_CLOSE';
    public const string VERB_ERROR = 'WS_ERROR';

    public function __construct(string|array|null $path = '')
    {
        parent::__construct($path);
    }

    public function getPath(): string
    {
        return '';
    }
}
