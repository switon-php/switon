<?php

declare(strict_types=1);

namespace Switon\Core\Tests\Unit;

use Switon\Core\PathAlias;
use Switon\Core\PathAliasInterface;
use Switon\Core\ResourceAliasRegistrar;
use Switon\Core\Tests\Fixtures\ResourceAliasAnnotatedProviderFixture;
use Switon\Core\Tests\Fixtures\ResourceAliasAutoAliasFixture;
use PHPUnit\Framework\TestCase;
use Closure;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

class ResourceAliasRegistrarTest extends TestCase
{
    protected function stubPathAlias(): object
    {
        return new class () implements PathAliasInterface {
            /** @var array<string, string> */
            protected array $map = [];

            public function resolve(string $path, array $context = []): string
            {
                return $path;
            }

            public function set(string $name, string $path): string
            {
                $this->map[$name] = $path;

                return $path;
            }

            public function get(string $name): ?string
            {
                return $this->map[$name] ?? null;
            }

            public function has(string $name): bool
            {
                return isset($this->map[$name]);
            }

            public function remove(string $name): void
            {
                unset($this->map[$name]);
            }

            /** @return array<string, string> */
            public function all(): array
            {
                return $this->map;
            }
        };
    }

    public function testRegisterSetsAliasWhenPackageLayoutMatches(): void
    {
        /** @var PathAliasInterface $pathAlias */
        $pathAlias = $this->stubPathAlias();

        (new ResourceAliasRegistrar())->register($pathAlias, ResourceAliasAnnotatedProviderFixture::class);

        $root = dirname(__DIR__, 2);
        $this->assertTrue($pathAlias->has('@test.fixture.resources'));
        $this->assertSame($root . '/resources', $pathAlias->get('@test.fixture.resources'));
    }

    public function testRegisterComputesVendorAliasWhenAliasAttributeLeftEmpty(): void
    {
        /** @var PathAliasInterface $pathAlias */
        $pathAlias = $this->stubPathAlias();

        (new ResourceAliasRegistrar())->register($pathAlias, ResourceAliasAutoAliasFixture::class);

        $coreRoot = dirname(__DIR__, 2);
        $this->assertTrue($pathAlias->has('@switon.core.validators'));
        $this->assertSame($coreRoot . '/validators', $pathAlias->get('@switon.core.validators'));
    }

    public function testRegisterSkipsPathsThatDoNotMatchMonorepoOrVendorPattern(): void
    {
        /** @var PathAliasInterface $pathAlias */
        $pathAlias = $this->stubPathAlias();

        $suffix = bin2hex(random_bytes(6));
        $namespace = 'Switon\\Core\\Tests\\Support\\TmpProvider' . $suffix;
        $dir = sys_get_temp_dir() . '/provider-resource-' . $suffix;
        mkdir($dir, 0777, true);
        $definition = "<?php declare(strict_types=1);\n"
            . "namespace {$namespace};\n\n"
            . "use Switon\\Core\\Attribute\\ResourceAlias;\n\n"
            . '#[ResourceAlias(alias: "@tmp.provider.unlayout")]' . "\n"
            . "class OutsiderProvider {}\n";
        $file = $dir . '/outsider-provider.php';
        file_put_contents($file, $definition);
        try {
            require_once $file;
            /** @var class-string $fqn */
            $fqn = "{$namespace}\\OutsiderProvider";
            (new ResourceAliasRegistrar())->register($pathAlias, $fqn);
        } finally {
            @unlink($file);
            @rmdir($dir);
        }

        $this->assertFalse($pathAlias->has('@tmp.provider.unlayout'));
    }

    public function testRegisterUsesAliasPathWithoutPackageDetection(): void
    {
        $pathAlias = new PathAlias();
        $pathAlias->set('@runtime', sys_get_temp_dir() . '/switon_resource_alias_runtime_' . bin2hex(random_bytes(4)));

        $suffix = bin2hex(random_bytes(6));
        $namespace = 'Switon\\Core\\Tests\\Support\\AliasPathProvider' . $suffix;
        $dir = sys_get_temp_dir() . '/provider-alias-path-' . $suffix;
        mkdir($dir, 0777, true);
        $definition = "<?php declare(strict_types=1);\n"
            . "namespace {$namespace};\n\n"
            . "use Switon\\Core\\Attribute\\ResourceAlias;\n\n"
            . '#[ResourceAlias(alias: "@threads", path: "@runtime/threads")]' . "\n"
            . "class AliasPathProvider {}\n";
        $file = $dir . '/alias-path-provider.php';
        file_put_contents($file, $definition);
        try {
            require_once $file;
            /** @var class-string $fqn */
            $fqn = "{$namespace}\\AliasPathProvider";
            (new ResourceAliasRegistrar())->register($pathAlias, $fqn);
        } finally {
            @unlink($file);
            @rmdir($dir);
        }

        $this->assertTrue($pathAlias->has('@threads'));
        $this->assertSame($pathAlias->get('@runtime') . '/threads', $pathAlias->get('@threads'));
    }

    public function testRegisterNoOpsWhenProviderHasNoSourceFile(): void
    {
        $pathAlias = $this->stubPathAlias();

        (new ResourceAliasRegistrar())->register($pathAlias, Closure::class);

        $this->assertSame([], $pathAlias->all());
    }

    public function testRegisterSkipsWhenComposerMetadataIsInvalid(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $packageRoot = sys_get_temp_dir() . '/provider-invalid-composer-' . $suffix;
        mkdir($packageRoot . '/src', 0777, true);
        file_put_contents($packageRoot . '/composer.json', '{"name": "vendor-only"}');

        $namespace = 'Tmp\\InvalidComposer' . $suffix;
        $definition = "<?php declare(strict_types=1);\n"
            . "namespace {$namespace};\n\n"
            . "use Switon\\Core\\Attribute\\ResourceAlias;\n\n"
            . '#[ResourceAlias(path: "resources")]' . "\n"
            . "class Provider {}\n";
        $file = $packageRoot . '/src/Provider.php';
        file_put_contents($file, $definition);
        try {
            require_once $file;
            /** @var class-string $fqn */
            $fqn = "{$namespace}\\Provider";
            $pathAlias = $this->stubPathAlias();
            (new ResourceAliasRegistrar())->register($pathAlias, $fqn);
            $this->assertFalse($pathAlias->has('@vendor-only.Provider.resources'));
        } finally {
            @unlink($file);
            @unlink($packageRoot . '/composer.json');
            @rmdir($packageRoot . '/src');
            @rmdir($packageRoot);
        }
    }

    public function testRegisterSkipsWhenComposerNameIsNotAString(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $packageRoot = sys_get_temp_dir() . '/provider-non-string-name-' . $suffix;
        mkdir($packageRoot . '/src', 0777, true);
        file_put_contents($packageRoot . '/composer.json', '{"name": null}');

        $namespace = 'Tmp\\NullComposerName' . $suffix;
        $definition = "<?php declare(strict_types=1);\n"
            . "namespace {$namespace};\n\n"
            . "use Switon\\Core\\Attribute\\ResourceAlias;\n\n"
            . '#[ResourceAlias(path: "views")]' . "\n"
            . "class Provider {}\n";
        $file = $packageRoot . '/src/Provider.php';
        file_put_contents($file, $definition);
        try {
            require_once $file;
            /** @var class-string $fqn */
            $fqn = "{$namespace}\\Provider";
            $pathAlias = $this->stubPathAlias();
            (new ResourceAliasRegistrar())->register($pathAlias, $fqn);
            $this->assertSame([], $pathAlias->all());
        } finally {
            @unlink($file);
            @unlink($packageRoot . '/composer.json');
            @rmdir($packageRoot . '/src');
            @rmdir($packageRoot);
        }
    }

    public function testRegisterSkipsWhenComposerNameHasNoPackageSegment(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $packageRoot = sys_get_temp_dir() . '/provider-empty-package-' . $suffix;
        mkdir($packageRoot . '/src', 0777, true);
        file_put_contents($packageRoot . '/composer.json', '{"name": "acme/"}');

        $namespace = 'Tmp\\EmptyPackageName' . $suffix;
        $definition = "<?php declare(strict_types=1);\n"
            . "namespace {$namespace};\n\n"
            . "use Switon\\Core\\Attribute\\ResourceAlias;\n\n"
            . '#[ResourceAlias(path: "views")]' . "\n"
            . "class Provider {}\n";
        $file = $packageRoot . '/src/Provider.php';
        file_put_contents($file, $definition);
        try {
            require_once $file;
            /** @var class-string $fqn */
            $fqn = "{$namespace}\\Provider";
            $pathAlias = $this->stubPathAlias();
            (new ResourceAliasRegistrar())->register($pathAlias, $fqn);
            $this->assertSame([], $pathAlias->all());
        } finally {
            @unlink($file);
            @unlink($packageRoot . '/composer.json');
            @rmdir($packageRoot . '/src');
            @rmdir($packageRoot);
        }
    }

    public function testRegisterSkipsWhenComposerJsonIsUnreadable(): void
    {
        if (!function_exists('posix_getuid') || posix_geteuid() === 0) {
            $this->markTestSkipped('Unreadable composer.json scenario requires a non-root POSIX user.');
        }

        $suffix = bin2hex(random_bytes(6));
        $packageRoot = sys_get_temp_dir() . '/provider-unreadable-composer-' . $suffix;
        mkdir($packageRoot . '/src', 0777, true);
        $composer = $packageRoot . '/composer.json';
        file_put_contents($composer, '{"name": "acme/pkg"}');
        chmod($composer, 0000);

        $namespace = 'Tmp\\UnreadableComposer' . $suffix;
        $definition = "<?php declare(strict_types=1);\n"
            . "namespace {$namespace};\n\n"
            . "use Switon\\Core\\Attribute\\ResourceAlias;\n\n"
            . '#[ResourceAlias(path: "migrations")]' . "\n"
            . "class Provider {}\n";
        $file = $packageRoot . '/src/Provider.php';
        file_put_contents($file, $definition);
        try {
            require_once $file;
            /** @var class-string $fqn */
            $fqn = "{$namespace}\\Provider";
            $pathAlias = $this->stubPathAlias();
            (new ResourceAliasRegistrar())->register($pathAlias, $fqn);
            $this->assertSame([], $pathAlias->all());
        } finally {
            @chmod($composer, 0600);
            @unlink($file);
            @unlink($composer);
            @rmdir($packageRoot . '/src');
            @rmdir($packageRoot);
        }
    }

    public function testRegisterNoOpsForClassesWithoutResourceAlias(): void
    {
        $pathAlias = $this->stubPathAlias();

        (new ResourceAliasRegistrar())->register($pathAlias, self::class);

        $this->assertSame([], $pathAlias->all());
    }

    public function testRegisterSkipsAliasPathWhenCustomAliasMissing(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $namespace = 'Switon\\Core\\Tests\\Support\\MissingAliasProvider' . $suffix;
        $dir = sys_get_temp_dir() . '/provider-missing-alias-' . $suffix;
        mkdir($dir, 0777, true);
        $definition = "<?php declare(strict_types=1);\n"
            . "namespace {$namespace};\n\n"
            . "use Switon\\Core\\Attribute\\ResourceAlias;\n\n"
            . '#[ResourceAlias(path: "@runtime/missing")]' . "\n"
            . "class MissingAliasProvider {}\n";
        $file = $dir . '/missing-alias-provider.php';
        file_put_contents($file, $definition);
        try {
            require_once $file;
            /** @var class-string $fqn */
            $fqn = "{$namespace}\\MissingAliasProvider";
            $pathAlias = $this->stubPathAlias();
            (new ResourceAliasRegistrar())->register($pathAlias, $fqn);
            $this->assertSame([], $pathAlias->all());
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }

    public function testRegisterLeavesExistingAliasesUnchanged(): void
    {
        /** @var PathAliasInterface $pathAlias */
        $pathAlias = $this->stubPathAlias();
        $existing = '/already/set';
        $pathAlias->set('@test.fixture.resources', $existing);

        (new ResourceAliasRegistrar())->register($pathAlias, ResourceAliasAnnotatedProviderFixture::class);

        $this->assertSame($existing, $pathAlias->get('@test.fixture.resources'));
    }
}
