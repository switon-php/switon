<?php

declare(strict_types=1);

namespace Switon\Ai\Part;

/**
 * {@see \Switon\Llm\RefReaderInterface::resolve()} returns a provider URL, local bytes, or null to unload; keep large
 * payloads out of transcripts. Opaque `$extra` is merged onto the provider media object at encode.
 */
class MediaPart extends ContentPart
{
    /**
     * @param array<string, mixed> $extra Opaque encode passthrough onto the provider media object.
     */
    public function __construct(
        public string  $ref = '',
        public ?string $mime = null,
        public array   $extra = [],
    ) {
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function of(string $ref, string $mime, array $extra = []): static
    {
        return new static($ref, $mime, $extra);
    }
}
