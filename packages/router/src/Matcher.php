<?php

declare(strict_types=1);

namespace Switon\Routing;

use Switon\Core\Naming;

use function preg_replace_callback;
use function str_contains;

/**
 * Stores the resolved handler and route variables for one successful route match.
 *
 * Use when dispatching a handler string and passing extracted parameters to controller actions.
 *
 * @see \Switon\Routing\MatcherInterface
 * @see \Switon\Routing\RouterInterface::match()
 * @see \Switon\Core\Naming
 */
class Matcher implements MatcherInterface
{
    /** Resolved handler string. */
    protected string $handler;

    /** @var array<string, mixed> Route variables after template replacement. */
    protected array $variables;

    /**
     * @param string $handler Handler string, optionally with placeholders like <code>{action}</code>.
     * @param array<string, mixed> $variables Route variables extracted from URI.
     */
    public function __construct(string $handler, array $variables = [])
    {
        if (str_contains($handler, '{')) {
            $handler = preg_replace_callback('#{([^}]+)}#', static function ($match) use (&$variables) {
                $name = $match[1];
                $value = $variables[$name] ?? '';

                unset($variables[$name]);
                return $name === 'action' ? Naming::camel($value) : Naming::pascal($value);
            }, $handler);
        }

        $this->handler = $handler;
        $this->variables = $variables;
    }

    /**
     * {@inheritDoc}
     */
    public function getHandler(): string
    {
        return $this->handler;
    }

    /**
     * {@inheritDoc}
     */
    public function getVariables(): array
    {
        return $this->variables;
    }

    /**
     * {@inheritDoc}
     */
    public function setVariables(array $variables): void
    {
        $this->variables = $variables;
    }
}
