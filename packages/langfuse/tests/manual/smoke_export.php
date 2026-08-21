<?php

declare(strict_types=1);

/**
 * Live Langfuse OTLP smoke test (real HTTP).
 *
 *   LANGFUSE_BASE_URL=https://cloud.langfuse.com \
 *   LANGFUSE_PUBLIC_KEY=pk-lf-... \
 *   LANGFUSE_SECRET_KEY=sk-lf-... \
 *   php packages/langfuse/tests/manual/smoke_export.php
 */

use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface as PsrListenerProviderInterface;
use Psr\Log\LoggerInterface;
use Switon\Core\App;
use Switon\Core\ContextManagerInterface;
use Switon\Di\Container as DiContainer;
use Switon\Di\ServiceProvider as DiServiceProvider;
use Switon\Eventing\EventDispatcher as RealEventDispatcher;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Langfuse\Client;
use Switon\Langfuse\ClientInterface;
use Switon\Langfuse\Observability\Span;
use Switon\Langfuse\Otlp;
use Switon\Testing\EventDispatcher as TestEventDispatcher;
use Switon\Testing\InMemoryContextManager;
use Switon\Testing\MockLogger;

require dirname(__DIR__, 4) . '/vendor/autoload.php';
require dirname(__DIR__, 4) . '/tests/bootstrap.php';

$baseUrl = getenv('LANGFUSE_BASE_URL') ?: '';
$publicKey = getenv('LANGFUSE_PUBLIC_KEY') ?: '';
$secretKey = getenv('LANGFUSE_SECRET_KEY') ?: '';

if ($baseUrl === '' || $publicKey === '' || $secretKey === '') {
    console_log('error', 'Set LANGFUSE_BASE_URL, LANGFUSE_PUBLIC_KEY, LANGFUSE_SECRET_KEY');

    exit(1);
}

$container = new DiContainer();
$di = new DiServiceProvider();
$di->register($container);
$di->boot();
App::setContainer($container);
$container->set(ContextManagerInterface::class, InMemoryContextManager::class);

$realDispatcher = $container->make(RealEventDispatcher::class);
$eventDispatcher = new TestEventDispatcher($realDispatcher);
$container->set(EventDispatcherInterface::class, $eventDispatcher);
$container->set(PsrEventDispatcherInterface::class, $eventDispatcher);
$container->set(PsrListenerProviderInterface::class, ListenerProviderInterface::class);

$logger = new MockLogger();
$container->set(LoggerInterface::class, $logger);
$container->set(ClientInterface::class, [
    'class' => Client::class,
    'baseUrl' => $baseUrl,
    'publicKey' => $publicKey,
    'secretKey' => $secretKey,
]);

/** @var Client $client */
$client = $container->get(ClientInterface::class);

$traceSeed = 'switon-smoke-' . gmdate('YmdHis');
$now = microtime(true);

console_log('info', 'Langfuse OTLP smoke: {url} trace {trace}', [
    'url' => $baseUrl,
    'trace' => $traceSeed,
]);

$client->ingest([
    new Span(
        'smoke-generation',
        Otlp::traceId($traceSeed),
        Otlp::spanId($traceSeed, 'smoke'),
        $now,
        $now + 0.5,
        [
            'langfuse.trace.name' => 'switon-smoke',
            'langfuse.session.id' => $traceSeed,
            'langfuse.observation.type' => 'generation',
            'langfuse.observation.model.name' => 'smoke-test',
            'langfuse.observation.input' => '{"role":"user","content":"ping from Switon langfuse"}',
            'langfuse.observation.output' => '"pong from Switon langfuse"',
            'langfuse.observation.usage_details' => '{"input":3,"output":5,"total":8}',
        ],
    ),
]);

if ($logger->logs !== []) {
    foreach ($logger->logs as $log) {
        console_log('warning', $log['message'], $log['context']);
    }

    exit(1);
}

console_log('info', 'OK — no errors logged. Search traces for {trace}', ['trace' => $traceSeed]);
