<?php

declare(strict_types=1);

namespace Switon\Mcp\Transport;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Exception\JsonException;
use Switon\Core\Json;
use Switon\Mcp\Event\TransportRead;
use Switon\Mcp\Event\TransportWrite;

use function fgets;
use function fflush;
use function fwrite;
use function is_array;
use function trim;

/**
 * Newline-delimited JSON-RPC over stdin/stdout.
 *
 * @see \Switon\Mcp\Transport\StdioTransportInterface
 * @see \Switon\Mcp\StdioServer
 */
class StdioTransport implements StdioTransportInterface
{
    #[Autowired] protected LoggerInterface $logger;
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;

    public function read(): ?array
    {
        $input = defined('STDIN') ? STDIN : fopen('php://stdin', 'rb');
        if (!is_resource($input)) {
            return null;
        }

        return $this->readFromStream($input);
    }

    /**
     * @param resource $stream
     *
     * @return array<string, mixed>|null
     */
    protected function readFromStream($stream): ?array
    {
        while (($line = fgets($stream)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            try {
                $request = Json::parse($line);
            } catch (JsonException) {
                $this->logStderr('Invalid JSON input on MCP stdio transport.');
                continue;
            }

            if (!is_array($request)) {
                $this->logStderr('MCP stdio request must decode to an object.');
                continue;
            }

            $this->eventDispatcher->dispatch(new TransportRead($request));

            return $request;
        }

        return null;
    }

    public function write(array $request, array $response): void
    {
        $event = new TransportWrite($request, $response);
        $this->eventDispatcher->dispatch($event);
        $response = $event->response;

        if ($response === []) {
            return;
        }

        $output = defined('STDOUT') ? STDOUT : fopen('php://stdout', 'wb');
        if (!is_resource($output)) {
            return;
        }

        fwrite($output, Json::stringify($response) . "\n");
        fflush($output);
    }

    protected function logStderr(string $message): void
    {
        $this->logger->warning($message);

        if (!defined('STDERR')) {
            return;
        }

        fwrite(STDERR, $message . PHP_EOL);
    }
}
