<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Agency\Tests\TestCase;
use Switon\Tooling\Tools;
use Switon\Tooling\ToolsInterface;

#[CoversClass(Tools::class)]
class AgencyToolsTest extends TestCase
{
    protected function tools(): ToolsInterface
    {
        return $this->container->get(ToolsInterface::class);
    }

    public function testAllIncludesAllowedAgencyTools(): void
    {
        $exposed = $this->tools()->pick(['recall', 'media', 'task_deliver']);

        $this->assertArrayHasKey('recall', $exposed);
        $this->assertArrayHasKey('media', $exposed);
        $this->assertArrayHasKey('task_deliver', $exposed);
    }

    public function testFullRegistryInstantiates(): void
    {
        $exposed = $this->make(Tools::class, [
            'tools' => $this->builtinToolConfig(),
        ])->pick(['thread_fork', 'form_ask']);

        $this->assertArrayHasKey('thread_fork', $exposed);
        $this->assertArrayHasKey('form_ask', $exposed);
    }

    public function testExposeFiltersByTools(): void
    {
        $this->assertSame(['recall'], array_keys($this->tools()->pick(['recall'])));
    }

    public function testAgencyToolSchemasAreRegistered(): void
    {
        $exposed = $this->tools()->pick(['recall', 'media']);

        $this->assertStringContainsString('For images/video use media', $exposed['recall']->definition['description']);
        $this->assertStringContainsString('thread directory', $exposed['recall']->definition['description']);
        $this->assertStringContainsString('@thread/', $exposed['recall']->definition['description']);
        $this->assertStringContainsString('Any file there is valid', $exposed['recall']->definition['description']);
        $this->assertStringContainsString('transcript.md', $exposed['recall']->definition['description']);
        $this->assertStringContainsString('Optional pattern greps the file', $exposed['recall']->definition['description']);
        $this->assertSame('transcript.md', $exposed['recall']->definition['inputSchema']['properties']['file']['default'] ?? null);
        $this->assertStringContainsString('Attach a media ref', $exposed['media']->definition['description']);
        $this->assertContains('prompt', $exposed['media']->definition['inputSchema']['required']);
        $this->assertContains('ref', $exposed['media']->definition['inputSchema']['required']);
        $this->assertArrayNotHasKey('additionalProperties', $exposed['media']->definition['inputSchema']);
        $this->assertArrayNotHasKey('extra', $exposed['media']->definition['inputSchema']['properties']);
    }
}
