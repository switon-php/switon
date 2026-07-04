<?php

declare(strict_types=1);

namespace Switon\Validating\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Switon\Core\Filesystem;
use Switon\Core\FilesystemInterface;
use Switon\Core\LocaleInterface;
use Switon\Core\PathAlias;
use Switon\Core\PathAliasInterface;
use Switon\Core\TranslatorInterface;
use Switon\Di\Container as DiContainer;
use Switon\Di\ServiceProvider as DiServiceProvider;
use Switon\Testing\Mock\StubTranslator;
use Switon\Validating\Validator;
use Switon\Validating\ValidatorInterface;

/**
 * Base test case for Validation tests.
 *
 * Builds a minimal DI container for {@see Validator} only — no full app provider scan.
 */
abstract class TestCase extends BaseTestCase
{
    protected DiContainer $container;
    protected ValidatorInterface $validator;
    protected LocaleInterface $locale;
    protected FilesystemInterface $filesystem;
    protected string $templateDir;

    protected function setUp(): void
    {
        parent::setUp();

        $pathAlias = new PathAlias();
        $this->templateDir = dirname(__DIR__) . '/src/Templates';
        $pathAlias->set('@switon.validator.resources', $this->templateDir);

        $this->locale = $this->createMock(LocaleInterface::class);
        $this->locale->expects($this->any())->method('get')->willReturn('en');
        $this->locale->expects($this->any())->method('set')->willReturnSelf();

        $this->filesystem = $this->createMock(Filesystem::class);
        $this->filesystem->expects($this->any())
            ->method('glob')
            ->willReturn([
                $this->templateDir . '/en.php',
                $this->templateDir . '/zh-cn.php',
            ]);

        $this->container = new DiContainer();
        $diProvider = new DiServiceProvider();
        $diProvider->register($this->container);
        $diProvider->boot();

        $this->container->set(PathAliasInterface::class, $pathAlias);
        $this->container->set(PathAlias::class, $pathAlias);
        $this->container->set(LocaleInterface::class, $this->locale);
        $this->container->set(FilesystemInterface::class, $this->filesystem);
        $this->container->set(TranslatorInterface::class, new StubTranslator());

        $this->validator = $this->container->make(Validator::class, [
            'dirs' => [$this->templateDir],
        ]);
    }
}
