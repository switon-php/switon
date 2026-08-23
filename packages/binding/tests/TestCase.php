<?php

declare(strict_types=1);

namespace Switon\Binding\Tests;

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

        $validatorTemplateDir = dirname((new \ReflectionClass(Validator::class))->getFileName()) . '/Templates';

        $pathAlias = new PathAlias();
        $pathAlias->set('@switon.validator.resources', $validatorTemplateDir);

        $locale = $this->createStub(LocaleInterface::class);
        $locale->method('get')->willReturn('en');
        $locale->method('set')->willReturnSelf();

        $filesystem = $this->createStub(FilesystemInterface::class);
        $filesystem->method('glob')
            ->willReturn([
                $validatorTemplateDir . '/en.php',
                $validatorTemplateDir . '/zh-cn.php',
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

        $validator = $this->container->make(Validator::class, ['dirs' => [$validatorTemplateDir]]);
        $this->container->set(ValidatorInterface::class, $validator);

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
