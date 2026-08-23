<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Agency\PromptVariableDiscovery;
use Switon\Agency\PromptVariableDiscoveryInterface;
use Switon\Agency\Tests\CatalogTestCase;
use Switon\Ai\AgentInterface;
use Switon\Ai\Profile;
use Switon\Ai\PromptRendererInterface;

#[CoversClass(PromptVariableDiscovery::class)]
class PromptVariableDiscoveryTest extends CatalogTestCase
{
    public function testDiscoverRegistersPromptVariables(): void
    {
        $this->container->get(PromptVariableDiscoveryInterface::class)->discover();

        $agent = $this->createStub(AgentInterface::class);
        $agent->method('profile')->willReturn(new Profile());
        $agent->method('workspace')->willReturn('');

        $this->assertSame(
            PHP_VERSION,
            $this->container->get(PromptRendererInterface::class)->render($agent, '{{ env.php }}'),
        );
    }
}
