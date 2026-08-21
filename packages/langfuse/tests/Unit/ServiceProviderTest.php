<?php

declare(strict_types=1);

namespace Switon\Langfuse\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Thread;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Langfuse\Client;
use Switon\Langfuse\ClientInterface;
use Switon\Langfuse\Observability\LlmListener;
use Switon\Langfuse\ServiceProvider;
use Switon\Langfuse\Tests\Fixtures\StubLlm;
use Switon\Langfuse\Tests\TestCase;
use Switon\Llm\Event\CompletionResponded;

#[CoversClass(ServiceProvider::class)]
class ServiceProviderTest extends TestCase
{
    public function testInterfaceAutoMapsToClient(): void
    {
        $client = $this->container->get(ClientInterface::class);
        $this->assertInstanceOf(Client::class, $client);
        $this->assertSame('', $client->baseUrl());
    }

    public function testBootRegistersListenerWhenBaseUrlConfigured(): void
    {
        $this->container->set(ClientInterface::class, [
            'class' => Client::class,
            'baseUrl' => 'https://cloud.langfuse.com',
            'publicKey' => 'pk-test',
            'secretKey' => 'sk-test',
        ]);

        $provider = new ServiceProvider();
        $provider->register($this->container);
        $this->injector->inject($provider);
        $provider->boot();

        $event = new CompletionResponded(new StubLlm(), new Thread('x'), Completion::of(UserMessage::of('hi')), Charter::of());
        $listeners = $this->container->get(ListenerProviderInterface::class)->getListenersForEvent($event);

        $found = false;
        foreach ($listeners as $listener) {
            $target = is_array($listener) ? $listener[0] : null;
            if ($target instanceof LlmListener) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found);
    }

    public function testBootSkipsListenerWhenBaseUrlEmpty(): void
    {
        $provider = new ServiceProvider();
        $this->injector->inject($provider);
        $provider->boot();

        $event = new CompletionResponded(new StubLlm(), new Thread('x'), Completion::of(UserMessage::of('hi')), Charter::of());
        $listeners = $this->container->get(ListenerProviderInterface::class)->getListenersForEvent($event);

        $found = false;
        foreach ($listeners as $listener) {
            $target = is_array($listener) ? $listener[0] : null;
            if ($target instanceof LlmListener) {
                $found = true;
                break;
            }
        }

        $this->assertFalse($found);
    }
}
