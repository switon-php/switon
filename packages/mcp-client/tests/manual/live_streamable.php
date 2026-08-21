<?php

declare(strict_types=1);

/**
 * Live streamable HTTP check. Reads ~/.cursor/mcp.json; tokens stay there.
 *
 *   php packages/mcp-client/tests/manual/live_streamable.php [server-id...]
 *
 * No ids: every entry that has `url` and no `command`.
 */

use Switon\Core\App;
use Switon\HttpClient\HttpClientInterface;
use Switon\McpClient\ClientInfo;
use Switon\McpClient\ClientInterface;
use Switon\McpClient\Transport\HttpTransportInterface;
use Switon\Testing\Container;

$repoRoot = dirname(__DIR__, 4);
require $repoRoot . '/vendor/autoload.php';
require $repoRoot . '/tests/bootstrap.php';

$container = new Container();
App::setContainer($container);
$container->set(HttpClientInterface::class, $container->make(HttpClientInterface::class, [
    'timeout' => 20,
    'connect_timeout' => 5,
]));

$path = ($_SERVER['HOME'] ?? '') . '/.cursor/mcp.json';
$decoded = json_decode((string) file_get_contents($path), true);
$catalog = is_array($decoded['mcpServers'] ?? null) ? $decoded['mcpServers'] : [];
$wanted = array_slice($argv, 1);

$targets = [];
foreach ($catalog as $id => $entry) {
    if (!is_array($entry) || !is_string($entry['url'] ?? null) || isset($entry['command'])) {
        continue;
    }
    if ($wanted !== [] && !in_array($id, $wanted, true)) {
        continue;
    }
    $targets[$id] = $entry;
}

if ($targets === []) {
    fwrite(STDERR, "No streamable servers in {$path}\n");
    exit(1);
}

$failed = 0;
foreach ($targets as $id => $entry) {
    echo "== {$id}\n" . endpoint($entry['url']) . "\n";
    $headers = [];
    foreach ($entry['headers'] ?? [] as $name => $value) {
        if (is_string($name) && is_string($value) && $value !== '') {
            $headers[$name] = $value;
        }
    }
    $clientInfo = ClientInfo::of('switon-mcp-client-live');
    $transport = $container->make(HttpTransportInterface::class, [
        'url' => $entry['url'],
        'headers' => $headers,
        'clientInfo' => $clientInfo,
    ]);
    $client = $container->make(ClientInterface::class, [
        'server' => $id,
        'brief' => $entry['url'],
        'transport' => $transport,
        'clientInfo' => $clientInfo,
    ]);

    try {
        $client->ping();
        $session = $transport->getSessionId();
        $sessionText = $session === null ? 'none' : 'set (' . strlen($session) . ' chars)';
        echo "ping ok; session {$sessionText}\n";

        $tools = $client->listTools();
        echo 'tools ' . count($tools) . "\n";
        $context = [];
        foreach ($tools as $name => $schema) {
            $arguments = sampleArguments($name, $schema->definition, $context);
            try {
                $result = $client->callTool($name, $arguments, raw: false);
                $text = is_string($result) ? $result : (json_encode($result) ?: '');
                remember($context, $text);
                $mark = is_array($result) && isset($result['error']) ? 'err' : 'ok';
                echo "{$mark} {$name} " . args($arguments) . ' => ' . clip($text) . "\n";
            } catch (Throwable $e) {
                echo 'FAIL ' . $name . ' ' . args($arguments) . ' => ' . $e::class . ': ' . clip($e->getMessage()) . "\n";
            }
        }
    } catch (Throwable $e) {
        $failed++;
        echo 'FAIL ' . $e::class . ': ' . $e->getMessage() . "\n";
    }

    unset($client, $transport);
    echo "\n";
}

exit($failed === 0 ? 0 : 1);

/**
 * Required fields only. Writes use a nonexistent id so the call is sent and the server rejects it.
 *
 * @param array<string, mixed> $definition
 * @param array<string, string> $context
 *
 * @return array<string, mixed>
 */
function sampleArguments(string $name, array $definition, array $context): array
{
    $schema = is_array($definition['inputSchema'] ?? null) ? $definition['inputSchema'] : [];
    $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
    $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
    $arguments = [];
    foreach ($required as $key) {
        if (!is_string($key)) {
            continue;
        }
        $property = is_array($properties[$key] ?? null) ? $properties[$key] : [];
        $arguments[$key] = sampleValue($name, $key, $property, $context);
    }

    return $arguments;
}

/**
 * @param array<string, mixed> $property
 * @param array<string, string> $context
 */
function sampleValue(string $tool, string $key, array $property, array $context): mixed
{
    if (isset($property['enum'][0]) && (is_string($property['enum'][0]) || is_int($property['enum'][0]))) {
        return $property['enum'][0];
    }
    $type = $property['type'] ?? 'string';
    if ($type === 'integer' || $type === 'number') {
        return 1;
    }
    if ($type === 'boolean') {
        return false;
    }
    if ($type === 'array') {
        return [];
    }
    if ($type === 'object') {
        return [];
    }
    if (!mutating($tool) && isset($context[$key])) {
        return $context[$key];
    }
    if (str_ends_with($key, 'id') || str_ends_with($key, 'Id')) {
        return '0';
    }

    return '';
}

function mutating(string $name): bool
{
    return preg_match('/delete|remove|create|update|upsert|trigger|restore|archive|upload|move|batch/i', $name) === 1;
}

/** @param array<string, string> $context */
function remember(array &$context, string $text): void
{
    if (preg_match('/"item_id"\s*:\s*"?(\d+)"?/', $text, $match) === 1) {
        $context['item_id'] = $match[1];
    }
    if (preg_match('/"page_id"\s*:\s*"?(\d+)"?/', $text, $match) === 1) {
        $context['page_id'] = $match[1];
    }
}

/** @param array<string, mixed> $arguments */
function args(array $arguments): string
{
    if ($arguments === []) {
        return '{}';
    }
    $parts = [];
    foreach ($arguments as $key => $value) {
        $parts[] = $key . '=' . (is_scalar($value) ? (string) $value : '{}');
    }

    return implode(' ', $parts);
}

function endpoint(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return $url;
    }
    $host = $parts['host'] ?? '';
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';

    return ($parts['scheme'] ?? 'http') . '://' . $host . $port . ($parts['path'] ?? '');
}

function clip(string|false $text): string
{
    if (!is_string($text)) {
        return '';
    }
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;

    return strlen($text) > 180 ? substr($text, 0, 180) . '…' : $text;
}
