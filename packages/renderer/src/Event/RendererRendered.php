<?php

declare(strict_types=1);

namespace Switon\Rendering\Event;

use JsonSerializable;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Rendering\RendererInterface;

/**
 * Event emitted after renderer executes a template.
 *
 * Log category: `switon.rendering.renderer.rendered`
 *
 * @see \Switon\Rendering\Renderer
 * @see \Switon\Rendering\Event\RendererRendering
 */
#[EventLevel(Severity::DEBUG)]
class RendererRendered implements JsonSerializable, SenderAwareInterface
{
    /**
     * @param array<string, mixed> $vars Template variables passed to the renderer
     */
    public function __construct(
        public RendererInterface $sender,
        public string            $template,
        public string            $file,
        public array             $vars,
    ) {

    }

    public function getSender(): RendererInterface
    {
        return $this->sender;
    }

    /**
     * Returns a privacy-safe event payload with variable keys and count only.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'renderer' => $this->sender::class,
            'template' => $this->template,
            'file' => $this->file,
            'vars' => [
                '_keys' => array_keys($this->vars),
                '_count' => count($this->vars),
            ],
        ];
    }
}
