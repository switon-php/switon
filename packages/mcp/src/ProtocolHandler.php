<?php

declare(strict_types=1);

namespace Switon\Mcp;

use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Json;
use Switon\Mcp\Event\ToolCalled;
use Switon\Mcp\Event\ToolCalling;
use Switon\Mcp\Exception\InvalidParamsException;
use Switon\Mcp\Exception\ToolNotFoundException;
use Switon\Mcp\Exception\UnsupportedMethodException;
use Throwable;

use function is_array;
use function is_string;
use function str_starts_with;

/**
 * Default MCP JSON-RPC protocol handler.
 *
 * @see \Switon\Mcp\ProtocolHandlerInterface
 */
class ProtocolHandler implements ProtocolHandlerInterface
{
    /** @var list<string> */
    protected const SUPPORTED_PROTOCOL_VERSIONS = ['2025-03-26', '2024-11-05'];

    #[Autowired] protected ServerInfoInterface $serverInfo;
    #[Autowired] protected ArgumentBinderInterface $argumentBinder;
    #[Autowired] protected NotificationsInterface $notifications;
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;

    public function handle(array $request, ToolsInterface $tools): array
    {
        $id = $request['id'] ?? null;

        if (!array_key_exists('method', $request)) {
            return $this->error($request, -32600, 'method is required');
        }

        if (!is_string($request['method'])) {
            return $this->error($request, -32600, 'method must be a string');
        }

        if (array_key_exists('params', $request) && !is_array($request['params'])) {
            return $this->error($request, -32602, 'params must be an object');
        }

        $method = $request['method'];
        $params = $request['params'] ?? [];

        try {
            if (str_starts_with($method, 'notifications/')) {
                if ($id !== null) {
                    return $this->error($request, -32600, 'Notifications must not include an id');
                }

                $this->notifications->handle($method, $params);

                return [];
            }

            $result = match ($method) {
                'initialize' => $this->initialize($params),
                'ping' => [],
                'tools/list' => $this->listTools($tools),
                'tools/call' => $this->invokeTool($params, $tools),
                'resources/list' => ['resources' => []],
                'prompts/list' => ['prompts' => []],
                default => UnsupportedMethodException::raise('Method not found'),
            };
        } catch (UnsupportedMethodException|ToolNotFoundException $e) {
            return $this->error($request, -32601, $e->getMessage());
        } catch (InvalidParamsException $e) {
            return $this->error($request, -32602, $e->getMessage());
        } catch (Throwable $e) {
            return $this->error($request, -32000, $e->getMessage());
        }

        if ($id === null) {
            return [];
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    protected function error(array $request, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $request['id'] ?? null,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    protected function initialize(array $params): array
    {
        $serverInfo = $this->serverInfo;
        $requested = $params['protocolVersion'] ?? null;
        $protocolVersion = is_string($requested) && in_array($requested, self::SUPPORTED_PROTOCOL_VERSIONS, true)
            ? $requested
            : $serverInfo->getProtocolVersion();

        $result = [
            'protocolVersion' => $protocolVersion,
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => $serverInfo->getName(),
                'version' => $serverInfo->getVersion(),
            ],
        ];

        $instructions = $serverInfo->getDescription();
        if ($instructions !== null && $instructions !== '') {
            $result['instructions'] = $instructions;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    protected function listTools(ToolsInterface $tools): array
    {
        $listed = [];
        foreach ($tools->all() as $name => $tool) {
            $schema = $tool->schema($name);
            if ($schema === null) {
                continue;
            }

            $listed[] = [
                'name' => $name,
                'description' => (string) ($schema->definition['description'] ?? ''),
                'inputSchema' => $schema->jsonSchema(),
            ];
        }

        return ['tools' => $listed];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    protected function invokeTool(array $params, ToolsInterface $tools): array
    {
        $name = $params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            InvalidParamsException::raise('name is required');
        }

        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            InvalidParamsException::raise('arguments must be an object');
        }

        $tool = $tools->get($name);
        if ($tool === null) {
            $result = new ToolResult(ToolNotFoundException::of(
                'MCP tool "{name}" was not found.',
                ['name' => $name],
            ));
        } else {
            $calling = new ToolCalling($name, $tool, $arguments);
            $this->eventDispatcher->dispatch($calling);
            $arguments = $calling->arguments;
            $result = $this->call($tool, $name, $arguments);
        }

        $content = $result->content;
        if ($content instanceof Throwable) {
            $content = ['class' => $content::class, 'content' => $content->getMessage()];
        }
        $text = Json::text($content);

        $encoded = [
            'content' => [
                ['type' => 'text', 'text' => $text],
            ],
        ];
        if ($result->isError) {
            $encoded['isError'] = true;
        }

        if ($tool === null) {
            return $encoded;
        }

        $called = new ToolCalled($name, $tool, $arguments, $encoded);
        $this->eventDispatcher->dispatch($called);

        return $called->result;
    }

    /**
     * Run one resolved tool after {@see Event\ToolCalling}.
     *
     * Guidance: return {@see ToolResult}, or throw {@see Exception\InvalidParamsException} so {@see handle()} maps it to JSON-RPC.
     *
     * @param array<string, mixed> $arguments
     */
    protected function call(ToolInterface $tool, string $name, array $arguments): ToolResult
    {
        try {
            $schema = $tool->schema($name);
            if ($schema === null) {
                ToolNotFoundException::raise('MCP tool "{name}" was not found.', ['name' => $name]);
            }

            $return = $tool->call($name, $this->argumentBinder->bind($schema, $arguments));

            return $return instanceof ToolResult ? $return : new ToolResult($return);
        } catch (Throwable $e) {
            return new ToolResult($e);
        }
    }
}
