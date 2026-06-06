<?php

declare(strict_types=1);

namespace Switon\Inspector\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\Core\CollectorInterface;
use Switon\Core\FilesystemInterface;
use Switon\Inspector\CollectorDiscoveryInterface;
use Switon\Inspector\HtmlRenderer;
use Switon\Inspector\Tests\TestCase;

#[AllowMockObjectsWithoutExpectations]
class HtmlRendererTest extends TestCase
{
    protected FilesystemInterface $filesystem;
    protected CollectorDiscoveryInterface $discovery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = $this->createMock(FilesystemInterface::class);
        $this->container->set(FilesystemInterface::class, $this->filesystem);

        $this->discovery = $this->createMock(CollectorDiscoveryInterface::class);
        $this->container->set(CollectorDiscoveryInterface::class, $this->discovery);
    }

    protected function makeRenderer(?array $tabs = null, ?string $pageTemplate = null): HtmlRenderer
    {
        $extra = [];
        if ($tabs !== null) {
            $extra['tabs'] = $tabs;
        }
        if ($pageTemplate !== null) {
            $extra['pageTemplate'] = $pageTemplate;
        }

        return $this->container->make(HtmlRenderer::class, $extra);
    }

    public function testRenderReplacesInspectorDataPlaceholder(): void
    {
        // Arrange
        $template = '<html><body>INSPECTOR_DATA</body></html>';
        $data = '{"test": "data"}';
        $expectedHtml = '<html><body>{"test": "data"}</body></html>';

        $this->filesystem->method('read')
            ->with($this->stringContains('inspector.html'))
            ->willReturn($template);

        $this->discovery->method('resolve')->willReturn([]);

        $renderer = $this->makeRenderer();

        // Act
        $result = $renderer->render($data);

        // Assert
        $this->assertSame($expectedHtml, $result);
    }

    public function testRenderReplacesComponentScriptsPlaceholder(): void
    {
        // Arrange
        $template = '<html><head><!-- COLLECTOR_COMPONENTS --></head></html>';
        $data = '{}';
        $componentScript = 'console.log("test");';

        $this->filesystem->method('read')
            ->willReturnCallback(function ($path) use ($template, $componentScript) {
                if (str_contains($path, 'inspector.html')) {
                    return $template;
                }
                if (str_contains($path, 'basic-tab.js')) {
                    return $componentScript;
                }
                return '';
            });

        $collector = $this->createMock(CollectorInterface::class);
        $this->discovery->method('resolve')->willReturn(['basic' => $collector]);

        $renderer = $this->makeRenderer(['basic']);

        // Act
        $result = $renderer->render($data);

        // Assert
        // Component scripts are appended with "\n" separator, so we check contains instead of exact match
        $this->assertStringContainsString('<html><head>', $result);
        $this->assertStringContainsString('console.log("test");', $result);
        $this->assertStringContainsString('</head></html>', $result);
    }

    public function testCollectComponentScriptsLoadsIndexedArrayTemplates(): void
    {
        // Arrange
        $template = '<html><!-- COLLECTOR_COMPONENTS --></html>';
        $basicScript = '// basic-tab.js';
        $sqlScript = '// sql-tab.js';

        $this->filesystem->method('read')
            ->willReturnCallback(function ($path) use ($template, $basicScript, $sqlScript) {
                if (str_contains($path, 'inspector.html')) {
                    return $template;
                }
                if (str_contains($path, 'basic-tab.js')) {
                    return $basicScript;
                }
                if (str_contains($path, 'sql-tab.js')) {
                    return $sqlScript;
                }
                return '';
            });

        $basicCollector = $this->createMock(CollectorInterface::class);
        $sqlCollector = $this->createMock(CollectorInterface::class);
        $this->discovery->method('resolve')->willReturn([
            'basic' => $basicCollector,
            'sql' => $sqlCollector,
        ]);

        $renderer = $this->makeRenderer(['basic', 'sql']);

        // Act
        $result = $renderer->render('{}');

        // Assert
        $this->assertStringContainsString($basicScript, $result);
        $this->assertStringContainsString($sqlScript, $result);
        // Note: Component scripts are appended with "\n" separator
    }

    public function testCollectComponentScriptsLoadsAssociativeArrayTemplates(): void
    {
        // Arrange
        $template = '<html><!-- COLLECTOR_COMPONENTS --></html>';
        $customScript = '// custom script';

        $this->filesystem->method('read')
            ->willReturnCallback(function ($path) use ($template, $customScript) {
                if (str_contains($path, 'inspector.html')) {
                    return $template;
                }
                if (str_contains($path, '/custom/path/script.js')) {
                    return $customScript;
                }
                return '';
            });

        $collector = $this->createMock(CollectorInterface::class);
        $this->discovery->method('resolve')->willReturn(['custom' => $collector]);

        $renderer = $this->makeRenderer(['custom' => '/custom/path/script.js']);

        // Act
        $result = $renderer->render('{}');

        // Assert
        $this->assertStringContainsString($customScript, $result);
    }

    public function testCollectComponentScriptsSkipsUnconfiguredCollectors(): void
    {
        // Arrange
        $template = '<html><!-- COLLECTOR_COMPONENTS --></html>';

        $this->filesystem->method('read')
            ->with($this->stringContains('inspector.html'))
            ->willReturn($template);

        $unconfiguredCollector = $this->createMock(CollectorInterface::class);
        $this->discovery->method('resolve')->willReturn([
            'unconfigured' => $unconfiguredCollector,
        ]);

        $renderer = $this->makeRenderer(['basic']); // Only 'basic' is configured

        // Act
        $result = $renderer->render('{}');

        // Assert
        $this->assertStringNotContainsString('unconfigured', $result);
    }

    public function testCollectComponentScriptsHandlesWindowsPathSeparator(): void
    {
        // Arrange
        $template = '<html><!-- COLLECTOR_COMPONENTS --></html>';
        $customScript = '// custom script';

        $this->filesystem->method('read')
            ->willReturnCallback(function ($path) use ($template, $customScript) {
                if (str_contains($path, 'inspector.html')) {
                    return $template;
                }
                if (str_contains($path, 'custom\\path\\script.js')) {
                    return $customScript;
                }
                return '';
            });

        $collector = $this->createMock(CollectorInterface::class);
        $this->discovery->method('resolve')->willReturn(['custom' => $collector]);

        $renderer = $this->makeRenderer(['custom' => 'custom\\path\\script.js']);

        // Act
        $result = $renderer->render('{}');

        // Assert
        $this->assertStringContainsString($customScript, $result);
    }

    public function testCollectComponentScriptsSkipsEmptyStringConfig(): void
    {
        // Arrange
        $template = '<html><!-- COLLECTOR_COMPONENTS --></html>';

        $this->filesystem->method('read')
            ->with($this->stringContains('inspector.html'))
            ->willReturn($template);

        $collector = $this->createMock(CollectorInterface::class);
        $this->discovery->method('resolve')->willReturn(['test' => $collector]);

        $renderer = $this->makeRenderer(['test' => '']); // Empty string config

        // Act
        $result = $renderer->render('{}');

        // Assert
        $this->assertStringNotContainsString('test', $result);
    }

    public function testCollectComponentScriptsSkipsNonStringConfig(): void
    {
        // Arrange
        $template = '<html><!-- COLLECTOR_COMPONENTS --></html>';

        $this->filesystem->method('read')
            ->with($this->stringContains('inspector.html'))
            ->willReturn($template);

        $collector = $this->createMock(CollectorInterface::class);
        $this->discovery->method('resolve')->willReturn(['test' => $collector]);

        $renderer = $this->makeRenderer(['test' => 123]); // Non-string config

        // Act
        $result = $renderer->render('{}');

        // Assert
        $this->assertStringNotContainsString('test', $result);
    }

    public function testCollectComponentScriptsLoadsMultipleTemplates(): void
    {
        // Arrange
        $template = '<html><!-- COLLECTOR_COMPONENTS --></html>';
        $scripts = [
            'basic' => '// basic script',
            'sql' => '// sql script',
            'logger' => '// logger script',
        ];

        $this->filesystem->method('read')
            ->willReturnCallback(function ($path) use ($template, $scripts) {
                if (str_contains($path, 'inspector.html')) {
                    return $template;
                }
                foreach ($scripts as $key => $script) {
                    if (str_contains($path, "$key-tab.js")) {
                        return $script;
                    }
                }
                return '';
            });

        $collectors = [];
        foreach (array_keys($scripts) as $key) {
            $collectors[$key] = $this->createMock(CollectorInterface::class);
        }
        $this->discovery->method('resolve')->willReturn($collectors);

        $renderer = $this->makeRenderer(array_keys($scripts));

        // Act
        $result = $renderer->render('{}');

        // Assert
        foreach ($scripts as $script) {
            $this->assertStringContainsString($script, $result);
        }
    }

    public function testRenderUsesCustomTemplatePath(): void
    {
        // Arrange
        $customTemplate = '<html><body>Custom Template</body></html>';
        $data = '{}';

        $this->filesystem->method('read')
            ->with('/custom/path/template.html')
            ->willReturn($customTemplate);

        $this->discovery->method('resolve')->willReturn([]);

        $renderer = $this->makeRenderer(null, '/custom/path/template.html');

        // Act
        $result = $renderer->render($data);

        // Assert
        $this->assertStringContainsString('Custom Template', $result);
    }
}
