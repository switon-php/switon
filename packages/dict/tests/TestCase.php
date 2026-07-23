<?php

declare(strict_types=1);

namespace Switon\Dict\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Switon\Core\App;
use Switon\Core\Filesystem;
use Switon\Core\FilesystemInterface;
use Switon\Core\InjectorInterface;
use Switon\Core\LocaleInterface;
use Switon\Core\PathAlias;
use Switon\Core\PathAliasInterface;
use Switon\Core\TranslatorInterface;
use Switon\Di\Container as DiContainer;
use Switon\Di\ServiceProvider as DiServiceProvider;
use Switon\Testing\Mock\StubTranslator;
use Switon\Validating\Validator;
use Switon\Validating\ValidatorInterface;

abstract class TestCase extends BaseTestCase
{
    protected DiContainer $container;
    protected InjectorInterface $injector;

    protected function setUp(): void
    {
        parent::setUp();

        $pathAlias = new PathAlias();
        $dictTemplateDir = dirname(__DIR__) . '/src/Templates';
        $pathAlias->set('@switon.dict.resources', $dictTemplateDir);

        $locale = $this->createStub(LocaleInterface::class);
        $locale->method('get')->willReturn('en');
        $locale->method('set')->willReturnSelf();

        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('glob')->willReturn([
            $dictTemplateDir . '/en.php',
            $dictTemplateDir . '/zh-cn.php',
        ]);

        $this->container = new DiContainer();
        $diProvider = new DiServiceProvider();
        $diProvider->register($this->container);
        $diProvider->boot();

        App::setContainer($this->container);

        $this->container->set(PathAliasInterface::class, $pathAlias);
        $this->container->set(PathAlias::class, $pathAlias);
        $this->container->set(LocaleInterface::class, $locale);
        $this->container->set(FilesystemInterface::class, $filesystem);
        $this->container->set(TranslatorInterface::class, new StubTranslator());
        $this->container->make(Validator::class, ['dirs' => [$dictTemplateDir]]);

        $this->injector = $this->container->get(InjectorInterface::class);
        $this->setUpContainer();
        $this->injector->inject($this);
    }

    protected function setUpContainer(): void
    {
    }

    protected function make(string $name, array $parameters = []): mixed
    {
        return $this->container->make($name, $parameters);
    }
}
