<?php

declare(strict_types=1);

namespace Switon\Llm;

use Switon\Ai\Part\MediaPart;

/**
 * Resolve {@see MediaPart::$ref} for provider encode.
 *
 * Place to add policy (scheme allowlist, size cap, …).
 * Return value: same string as {@see MediaPart::$ref} → remote URL; any other non-null string → local
 * file bytes; null → unload / unsupported.
 * Relative path refs join `$datadir` ({@see \Switon\Core\Path::resolve} blocks `..` escape).
 * Absolute refs read directly — same reach as the `media` tool, no jail.
 *
 * @see \Switon\Llm\RefReader
 */
interface RefReaderInterface
{
    /**
     * @param string $datadir Base directory for relative refs (may be a path alias).
     */
    public function resolve(MediaPart $part, string $datadir = ''): ?string;
}
