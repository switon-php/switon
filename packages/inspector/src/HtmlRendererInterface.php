<?php

declare(strict_types=1);

namespace Switon\Inspector;

/**
 * Defines HTML rendering contract for inspector responses.
 *
 * Use when replacing the default inspector UI renderer.
 *
 * @see \Switon\Inspector\HtmlRenderer
 */
interface HtmlRendererInterface
{
    /**
     * Renders inspector data as HTML.
     *
     * @param string $data JSON-encoded inspector data
     *
     * @return string Complete HTML page
     */
    public function render(string $data): string;
}
