<?php

declare(strict_types=1);

namespace Switon\Beacon\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Switon\Beacon\Command\ConfigCommand;
use Switon\Beacon\Command\PathCommand;
use Switon\Core\App;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;
use Switon\Core\Exception\FileNotFoundException;
use Switon\Core\FilesystemInterface;
use Switon\Testing\Container;
use ArrayAccess;
use ArrayIterator;
use Countable;
use Exception;
use ReflectionProperty;
use stdClass;

use function array_column;
use function file_put_contents;
use function json_decode;
use function mkdir;
use function rtrim;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[AllowMockObjectsWithoutExpectations]
class ConfigCommandTest extends TestCase
{
    protected ConfigCommand $command;
    protected ConsoleInterface&MockObject $console;
    protected ContainerInterface&MockObject $container;

    protected function setUp(): void
    {
        parent::setUp();
        $this->console = $this->createMock(ConsoleInterface::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->command = $this->makeCommand(['container' => $this->container]);
    }

    public function testOptionsActionReturnsErrorWhenClassIsMissing(): void
    {
        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->optionsAction('Not\\Existing\\Class', true);

        $this->assertSame(1, $code);
        $this->assertSame('class not found', $captured['error']);
        $this->assertSame('Not\\Existing\\Class', $captured['class']);
    }

    public function testOptionsActionReturnsAutowiredOptionsForConcreteClass(): void
    {
        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->optionsAction(PathCommand::class, true);

        $this->assertSame(0, $code);
        $this->assertSame(PathCommand::class, $captured['class']);
        $this->assertSame('config/path-command.php', $captured['config_file']);
        $names = array_column($captured['options'], 'name');
        $this->assertContains('classIndex', $names);
        $this->assertContains('console', $names);
    }

    public function testOptionsActionResolvesInterfaceFromContainer(): void
    {
        $captured = null;
        $this->container->expects($this->once())
            ->method('get')
            ->with(ConfigCommandTestInterface::class)
            ->willReturn(new PathCommand());
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->optionsAction(ConfigCommandTestInterface::class, true);

        $this->assertSame(0, $code);
        $this->assertSame(PathCommand::class, $captured['class']);
        $this->assertSame('config/config-command-test.php', $captured['config_file']);
    }

    public function testOptionsActionExtractsClassFromExceptionMessage(): void
    {
        $captured = null;
        $this->container->expects($this->once())
            ->method('get')
            ->with(ConfigCommandTestInterface::class)
            ->willThrowException(new Exception(App::class . '::$id is not nullable and has no default value'));
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->optionsAction(ConfigCommandTestInterface::class, true);

        $this->assertSame(0, $code);
        $this->assertSame(App::class, $captured['class']);
        $this->assertArrayHasKey('options', $captured);
        $this->assertNotEmpty($captured['options']);
    }

    public function testOptionsActionReturnsErrorWhenInterfaceResolveFailsWithoutFqcnHint(): void
    {
        $this->container->expects($this->once())->method('get')
            ->with(ConfigCommandTestInterface::class)
            ->willThrowException(new Exception('resolution failed'));

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->optionsAction(ConfigCommandTestInterface::class, true);

        $this->assertSame(1, $code);
        $this->assertSame('class not found', $captured['error']);
    }

    public function testOptionsActionWritesReadablePlainTextHeader(): void
    {
        $lines = [];
        $this->console->expects($this->atLeastOnce())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$lines): void {
                $lines[] = $line;
            });

        $code = $this->command->optionsAction(PathCommand::class, false);

        $this->assertSame(0, $code);
        $this->assertNotEmpty($lines);
        $this->assertSame('Config options for ' . PathCommand::class . ':', $lines[0]);
    }

    public function testOptionsActionInfersInstancesTypeFromDefaultArray(): void
    {
        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->optionsAction(ConfigCommandInstancesDefaultFixture::class, true);

        $this->assertSame(0, $code);
        $map = [];
        foreach ($captured['options'] as $option) {
            $map[$option['name']] = $option;
        }

        $this->assertSame(
            'array<' . ConfigCommandTestInterface::class . '>',
            $map['many']['type']
        );
        $this->assertTrue($map['many']['instances']);
    }

    public function testOptionsActionInfersInstancesTypeFromConfiguredEntries(): void
    {
        $container = new Container([
            ConfigCommandInstancesConfiguredFixture::class => [
                'many' => [
                    'default' => PathCommand::class,
                    'inline' => ['class' => App::class],
                    'service' => 'config.path',
                ],
            ],
            'config.path' => PathCommand::class,
        ]);
        $this->command = $this->makeCommand(['container' => $container]);

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->optionsAction(ConfigCommandInstancesConfiguredFixture::class, true);

        $this->assertSame(0, $code);
        $map = [];
        foreach ($captured['options'] as $option) {
            $map[$option['name']] = $option;
        }

        $this->assertSame(
            'array<' . PathCommand::class . '|' . App::class . '>',
            $map['many']['type']
        );
        $this->assertSame(
            ['default' => ConfigCommandTestInterface::class],
            $map['many']['default']
        );
    }

    public function testConfigFileHintRemovesInterfaceSuffixAndConvertsToKebab(): void
    {
        $harness = new ConfigCommandHarness();

        $this->assertSame('config/config-command-test.php', $harness->exposedConfigFileHint(ConfigCommandTestInterface::class));
        $this->assertSame('config/my-service.php', $harness->exposedConfigFileHint('App\\Service\\MyService'));
    }

    public function testCollectAutowiredPropertiesMergesParentAndKeepsChildOverride(): void
    {
        $harness = new ConfigCommandHarness();
        $options = $harness->exposedCollectAutowiredProperties(ConfigCommandInheritedFixture::class);

        $map = [];
        foreach ($options as $option) {
            $map[$option['name']] = $option;
        }

        $this->assertArrayHasKey('shared', $map);
        $this->assertSame('string', $map['shared']['type']);
        $this->assertSame('child', $map['shared']['default']);
        $this->assertArrayHasKey('parentOnly', $map);
        $this->assertArrayHasKey('many', $map);
        $this->assertTrue($map['many']['instances']);
        $this->assertSame('array', $map['many']['type']);
    }

    public function testCollectAutowiredPropertiesReturnsMixedForIntersectionType(): void
    {
        $harness = new ConfigCommandHarness();
        $options = $harness->exposedCollectAutowiredProperties(ConfigCommandIntersectionFixture::class);

        $this->assertCount(1, $options);
        $this->assertSame('both', $options[0]['name']);
        $this->assertSame('mixed', $options[0]['type']);
    }

    public function testCheckActionReturnsErrorWhenFileCannotBeRead(): void
    {
        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $file = sys_get_temp_dir() . '/missing-switon-' . uniqid() . '.yml';
        $code = $this->command->checkAction($file, '', false, false);

        $this->assertSame(1, $code);
        $this->assertFalse($captured['ok']);
        $this->assertSame($file, $captured['file']);
        $this->assertStringContainsString('Failed to read', $captured['errors'][0]);
    }

    public function testCheckActionReportsAReadThatDisappears(): void
    {
        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });
        $filesystem = $this->createStub(FilesystemInterface::class);
        $filesystem->method('isFile')->willReturn(true);
        $filesystem->method('isReadable')->willReturn(true);
        $filesystem->method('read')->willThrowException(FileNotFoundException::of('gone'));
        $command = $this->makeHarness([FilesystemInterface::class => $filesystem]);

        $code = $command->checkAction('/tmp/switon.yml', '', false, false);

        $this->assertSame(1, $code);
        $this->assertFalse($captured['ok']);
    }

    public function testCheckActionReportsWarningForUnknownFqcnLikeServiceId(): void
    {
        $file = sys_get_temp_dir() . '/warn-switon-' . uniqid() . '.yml';
        file_put_contents($file, "Not\\\\Existing\\\\Service:\n  class: Not\\\\Existing\\\\Service\n");

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->checkAction($file, '', false, false);

        $this->assertSame(0, $code);
        $this->assertTrue($captured['ok']);
        $this->assertNotEmpty($captured['warnings']);
        $this->assertStringContainsString(
            'Service id looks like FQCN but class/interface not found',
            $captured['warnings'][0]
        );

        @unlink($file);
    }

    public function testCheckActionReturnsYamlParseErrorForInvalidYaml(): void
    {
        $file = sys_get_temp_dir() . '/parse-switon-' . uniqid() . '.yml';
        file_put_contents($file, "123\n");

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->checkAction($file, '', false, false);

        $this->assertSame(1, $code);
        $this->assertFalse($captured['ok']);
        $this->assertTrue(
            str_contains($captured['errors'][0], 'YAML parse error:')
            || str_contains($captured['errors'][0], 'YAML root must be a mapping or sequence.')
        );

        @unlink($file);
    }

    public function testCheckActionDetectsYamlPhpTypeConflict(): void
    {
        $base = rtrim(sys_get_temp_dir(), '/') . '/cfg-conflict-' . uniqid();
        mkdir($base . '/config', 0777, true);
        $yaml = $base . '/switon.yml';
        $php = $base . '/config/services.php';

        file_put_contents($yaml, "service.a:\n  class: Switon\\\\Beacon\\\\Command\\\\PathCommand\n");
        file_put_contents($php, "<?php\nreturn ['service.a' => 'string-definition'];\n");

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->checkAction($yaml, 'config', false, false);

        $this->assertSame(1, $code);
        $this->assertFalse($captured['ok']);
        $this->assertStringContainsString('YAML/PHP type conflict for "service.a"', $captured['errors'][0]);
    }

    public function testCheckActionSkipsUnknownKeyWarningForFactoryDefinitions(): void
    {
        $file = sys_get_temp_dir() . '/factory-key-switon-' . uniqid() . '.yml';
        file_put_contents(
            $file,
            "factory_service:\n  class: Switon\\\\Di\\\\Factory\n  randomFactoryKey: value\n"
        );

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->checkAction($file, '', false, false);

        $this->assertSame(0, $code);
        $this->assertTrue($captured['ok']);
        $this->assertSame([], $captured['warnings']);

        @unlink($file);
    }

    public function testCheckActionWarnsOnUnknownYamlKeyForConcreteService(): void
    {
        $classQuotedForYaml = '"' . str_replace('\\', '\\\\', PathCommand::class) . '"';
        $yaml = <<<YAML
alias_svc:
  class: {$classQuotedForYaml}
  beacon_unknown_yaml_key_xx: true
YAML;
        $file = sys_get_temp_dir() . '/unknown-yaml-key-' . uniqid('', true) . '.yml';
        file_put_contents($file, $yaml);

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->checkAction($file, '', false, false);

        $this->assertSame(0, $code);
        $this->assertTrue($captured['ok']);
        $this->assertNotEmpty($captured['warnings']);
        $hint = implode(' ', $captured['warnings']);
        $this->assertStringContainsString('Unknown config key "beacon_unknown_yaml_key_xx"', $hint);
        $this->assertStringContainsString('alias_svc', $hint);

        @unlink($file);
    }

    public function testInferServiceTypeReadsObjectDefinitions(): void
    {
        $harness = $this->makeHarness([
            'svc-object' => new stdClass(),
        ]);

        $this->assertSame('stdClass', $harness->exposedInferServiceType('svc-object'));
    }

    public function testInferServiceTypeFollowsHashSuffixAlias(): void
    {
        $harness = $this->makeHarness([
            'named' => '#real',
            'named#real' => PathCommand::class,
        ]);

        $this->assertSame(PathCommand::class, $harness->exposedInferServiceType('named'));
    }

    public function testInferServiceTypeResolvesFqcnWhenArrayDefinitionOmitsClassKey(): void
    {
        $harness = $this->makeHarness([
            PathCommand::class => [],
        ]);

        $this->assertSame(PathCommand::class, $harness->exposedInferServiceType(PathCommand::class));
    }

    public function testInferServiceTypeReturnsObjectWhenAliasChainsCycle(): void
    {
        $harness = $this->makeHarness([
            'a' => 'b',
            'b' => 'a',
        ]);

        $this->assertSame('object', $harness->exposedInferServiceType('a'));
    }

    public function testInferServiceTypeReturnsSeenMarkerForRecursiveId(): void
    {
        $harness = $this->makeHarness();

        $this->assertSame('object', $harness->exposedInferServiceType(PathCommand::class, [PathCommand::class => true]));
    }

    public function testInferServiceTypeReturnsObjectForEmptyId(): void
    {
        $harness = $this->makeHarness();

        $this->assertSame('object', $harness->exposedInferServiceType(''));
    }

    public function testInferServiceTypeResolvesBareStringAliasChain(): void
    {
        $harness = $this->makeHarness([
            'alias.path' => PathCommand::class,
        ]);

        $this->assertSame(PathCommand::class, $harness->exposedInferServiceType('alias.path'));
    }

    public function testInferServiceTypeReturnsBaseFqcnBeforeHashDelimiter(): void
    {
        $harness = $this->makeHarness();

        $this->assertSame(PathCommand::class, $harness->exposedInferServiceType(PathCommand::class . '#slot'));
    }

    public function testFilterNullEntriesRemovesAssociativeNulls(): void
    {
        $harness = new ConfigCommandHarness();
        $this->assertSame(['a' => 1], $harness->exposedFilterNullEntries(['a' => 1, 'b' => null]));
    }

    public function testBuildInterpolationVariablesExportsScalarYamlParameters(): void
    {
        $harness = $this->makeHarness();
        $vars = $harness->exposedBuildCheckInterpolationVariables([
            'parameters' => [
                'flagTrue' => true,
                'flagFalse' => false,
                'count' => 9,
            ],
        ]);

        $this->assertSame('true', $vars['flagTrue']);
        $this->assertSame('false', $vars['flagFalse']);
        $this->assertSame('9', $vars['count']);
    }

    public function testKnownConfigKeysForClassIncludesAutowiredPropertiesAndConstructorParameters(): void
    {
        $harness = $this->makeHarness();
        $keys = $harness->exposedKnownConfigKeysForClass(ConfigCommandKnownKeysFixture::class);

        $this->assertArrayHasKey('childKey', $keys);
        $this->assertArrayHasKey('parentKey', $keys);
        $this->assertArrayHasKey('ctorKey', $keys);
        $this->assertArrayNotHasKey('ignored', $keys);
    }

    public function testLoadPhpConfigForCheckSkipsHiddenFilesAndNonArrayReturns(): void
    {
        $dir = rtrim(sys_get_temp_dir(), '/') . '/cfg-load-' . uniqid('', true);
        mkdir($dir, 0777, true);

        file_put_contents($dir . '/alpha.php', "<?php\nreturn ['first' => 1];\n");
        file_put_contents($dir . '/beta.php', "<?php\nreturn 'nope';\n");
        file_put_contents($dir . '/.hidden.php', "<?php\nreturn ['hidden' => true];\n");

        try {
            $harness = $this->makeHarness();
            $config = $harness->exposedLoadPhpConfigForCheck($dir);

            $this->assertSame(['first' => 1], $config);
        } finally {
            @unlink($dir . '/alpha.php');
            @unlink($dir . '/beta.php');
            @unlink($dir . '/.hidden.php');
            @rmdir($dir);
        }
    }

    public function testDescribeInstancesTypeInfersConfiguredEntryUnion(): void
    {
        $harness = $this->makeHarness([
            ConfigCommandDescribeFixture::class => [
                'many' => [
                    ConfigCommandTestInterface::class,
                    ['class' => PathCommand::class],
                    'config.path',
                    new stdClass(),
                    '',
                    null,
                    false,
                ],
            ],
            'config.path' => PathCommand::class,
        ]);

        $property = new ReflectionProperty(ConfigCommandDescribeFixture::class, 'many');
        $type = $harness->exposedDescribeInstancesType($property, [], ['', 'missing', ConfigCommandDescribeFixture::class]);

        $this->assertStringStartsWith('array<', $type);
        $this->assertStringContainsString(ConfigCommandTestInterface::class, $type);
        $this->assertStringContainsString(PathCommand::class, $type);
        $this->assertStringContainsString('stdClass', $type);
    }

    public function testMergeConfiguredInstancesEntriesHandlesTraversableAndNullOverrides(): void
    {
        $harness = $this->makeHarness();
        $property = new ReflectionProperty(ConfigCommandMergeFixture::class, 'many');
        $object = new stdClass();

        $merged = $harness->exposedMergeConfiguredInstancesEntries($property, [
            'keep' => null,
            'object' => $object,
            'service' => 'config.path',
            'skip' => null,
        ]);

        $this->assertSame([
            'default' => ConfigCommandTestInterface::class,
            'object' => $object,
            'service' => 'config.path',
        ], $merged);

        $traversable = $harness->exposedMergeConfiguredInstancesEntries($property, new ArrayIterator([
            'a' => 1,
            'b' => null,
        ]));

        $this->assertSame([
            'a' => 1,
            'b' => null,
        ], $traversable);
    }

    public function testCollectAutowiredReflectsUnionPropertyType(): void
    {
        $harness = $this->makeHarness();
        $options = $harness->exposedCollectAutowiredProperties(ConfigCommandUnionFixture::class);

        $this->assertCount(1, $options);
        $this->assertSame('either', $options[0]['name']);
        $this->assertSame('string|int', $options[0]['type']);
    }

    protected function makeCommand(array $parameters = []): ConfigCommand
    {
        $container = new Container([
            ConsoleInterface::class => $this->console,
        ]);

        return $container->make(ConfigCommand::class, $parameters);
    }

    protected function makeHarness(array $definitions = []): ConfigCommandHarness
    {
        $container = new Container(array_merge([
            ConsoleInterface::class => $this->console,
        ], $definitions));

        return $container->make(ConfigCommandHarness::class);
    }
}

interface ConfigCommandTestInterface
{
}

class ConfigCommandHarness extends ConfigCommand
{
    public function exposedConfigFileHint(string $fqcn): string
    {
        return $this->configFileHint($fqcn);
    }

    public function exposedKnownConfigKeysForClass(string $class): array
    {
        return $this->knownConfigKeysForClass($class);
    }

    /** @return array<string, mixed> */
    public function exposedLoadPhpConfigForCheck(string $dir): array
    {
        return $this->loadPhpConfigForCheck($dir);
    }

    public function exposedCollectAutowiredProperties(string $className): array
    {
        return $this->collectAutowiredProperties($className);
    }

    /**
     * @param list<string> $configIds
     *
     * @return list<array{name: string, type: string, instances: bool, default: mixed}>
     */
    public function exposedDescribeInstancesType(ReflectionProperty $property, mixed $default, array $configIds): string
    {
        return $this->describeInstancesType($property, $default, $configIds);
    }

    /** @param list<string> $configIds */
    public function exposedResolveConfiguredInstancesEntries(ReflectionProperty $property, array $configIds): ?array
    {
        return $this->resolveConfiguredInstancesEntries($property, $configIds);
    }

    public function exposedMergeConfiguredInstancesEntries(ReflectionProperty $property, mixed $configValue): ?array
    {
        return $this->mergeConfiguredInstancesEntries($property, $configValue);
    }

    public function exposedInferServiceType(string $id, array $seen = []): string
    {
        return $this->inferServiceType($id, $seen);
    }

    /** @return array<string, string> */
    public function exposedBuildCheckInterpolationVariables(array $yaml): array
    {
        return $this->buildCheckInterpolationVariables($yaml);
    }

    /** @return array<string, mixed> */
    public function exposedFilterNullEntries(array $entries): array
    {
        return $this->filterNullEntries($entries);
    }
}

class ConfigCommandInheritedParentFixture
{
    #[Autowired] protected string $shared = 'parent';

    #[Autowired] protected int $parentOnly = 7;
}

class ConfigCommandInheritedFixture extends ConfigCommandInheritedParentFixture
{
    #[Autowired] protected string $shared = 'child';

    #[Autowired(true)] protected array $many = [];
}

interface ConfigCommandCountableIterable extends Countable, \IteratorAggregate
{
}

class ConfigCommandIntersectionFixture
{
    #[Autowired] protected ConfigCommandCountableIterable&ArrayAccess $both;
}

class ConfigCommandInstancesDefaultFixture
{
    #[Autowired(true)] protected array $many = ['default' => ConfigCommandTestInterface::class];
}

class ConfigCommandInstancesConfiguredFixture
{
    #[Autowired(true)] protected array $many = ['default' => ConfigCommandTestInterface::class];
}

class ConfigCommandUnionFixture
{
    #[Autowired] protected string|int $either = '';
}

class ConfigCommandKnownKeysParentFixture
{
    #[Autowired] protected string $parentKey = '';

    protected string $ignored = '';
}

class ConfigCommandKnownKeysFixture extends ConfigCommandKnownKeysParentFixture
{
    #[Autowired] protected string $childKey = '';

    public function __construct(string $ctorKey = '')
    {
        $this->ctorKey = $ctorKey;
    }
}

class ConfigCommandDescribeFixture
{
    #[Autowired(true)] protected array $many = [];
}

class ConfigCommandMergeFixture
{
    #[Autowired(true)] protected array $many = [
        'default' => ConfigCommandTestInterface::class,
        'keep' => PathCommand::class,
    ];
}
