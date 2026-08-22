<?php

declare(strict_types=1);

/**
 * Manual smoke for {@see \Switon\Search\Tool\WebSearchTool}.
 *
 *   php packages/search/tests/manual/web_search_smoke.php [query]
 */

use Switon\Ai\ToolCall;
use Switon\Core\App;
use Switon\Search\Tests\Fixtures\StubAgent;
use Switon\Search\Tool\WebSearchTool;
use Switon\Testing\Container;

$repoRoot = dirname(__DIR__, 4);
require $repoRoot . '/vendor/autoload.php';
require $repoRoot . '/tests/bootstrap.php';

$container = new Container();
App::setContainer($container);

$query = $argv[1] ?? 'Switon PHP framework';
$tool = $container->make(WebSearchTool::class);

$call = new ToolCall(name: 'web_search', arguments: ['query' => $query]);
$result = $tool->call(StubAgent::in(), $call, $call->arguments);
echo 'count: ' . count($result['results']) . "\n";
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
