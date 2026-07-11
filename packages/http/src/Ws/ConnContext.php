<?php

declare(strict_types=1);

namespace Switon\Http\Ws;

/** Worker-local fd → bound WebSocket controller. */
class ConnContext implements ConnContextInterface
{
    /** @var array<int, array{controller: string, variables: array<string, mixed>, path: string}> */
    protected array $connections = [];

    /**
     * Bind a WebSocket controller to a worker-local connection fd.
     *
     * @param array<string, mixed> $variables Route variables bound to the controller
     */
    public function bind(int $fd, string $controller, array $variables = [], string $path = ''): void
    {
        $this->connections[$fd] = [
            'controller' => $controller,
            'variables' => $variables,
            'path' => $path,
        ];
    }

    public function get(int $fd): ?array
    {
        return $this->connections[$fd] ?? null;
    }

    public function remove(int $fd): void
    {
        unset($this->connections[$fd]);
    }
}
