<?php

declare(strict_types=1);

namespace Switon\Binding\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionParameter;
use Switon\Binding\Exception\TooManyPositionalArgumentsException;
use Switon\Binding\ScalarResolver;
use Switon\Core\InputInterface;
use Switon\Core\PositionalInputInterface;

class ScalarResolverTest extends TestCase
{
    public function testResolveUsesExactNameLookupBeforePositional(): void
    {
        $resolver = $this->createScalarResolver([
            'name' => 'named',
        ]);
        $parameter = (new ReflectionMethod(ScalarResolverFixture::class, 'handle'))->getParameters()[0];

        self::assertSame('named', $resolver->resolve($parameter, 'string'));
    }

    public function testResolveUsesSnakeCaseLookup(): void
    {
        $resolver = $this->createScalarResolver([
            'post_id' => '42',
        ]);
        $parameter = (new ReflectionMethod(ScalarResolverFixture::class, 'handleSnake'))->getParameters()[0];

        self::assertSame('42', $resolver->resolve($parameter, 'string'));
    }

    public function testResolveReturnsEmptyStringForEmptyStringInput(): void
    {
        $resolver = $this->createScalarResolver([
            'name' => '',
        ]);
        $parameter = (new ReflectionMethod(ScalarResolverFixture::class, 'handle'))->getParameters()[0];

        self::assertSame('', $resolver->resolve($parameter, 'string'));
    }

    public function testResolveUsesLastArrayValueForScalarParameter(): void
    {
        $resolver = $this->createScalarResolver([
            'name' => ['first', 'last'],
        ]);
        $parameter = (new ReflectionMethod(ScalarResolverFixture::class, 'handleArrayValue'))->getParameters()[0];

        self::assertSame('last', $resolver->resolve($parameter, 'string'));
    }

    public function testResolveUsesTruthyBoolWhenFlagIsPresentWithoutValue(): void
    {
        $resolver = $this->createScalarResolver([
            'enabled' => null,
        ]);
        $parameter = (new ReflectionMethod(ScalarResolverFixture::class, 'handleBool'))->getParameters()[0];

        self::assertTrue($resolver->resolve($parameter, 'bool'));
    }

    public function testResolveTruthyBoolWhenOnlySnakeCaseKeyIsPresentViaHas(): void
    {
        $input = new class () implements InputInterface {
            public function has(string|int $name): bool
            {
                return $name === 'accept_terms';
            }

            public function get(string|int $name, mixed $default = null): mixed
            {
                return $default;
            }

            public function all(): array
            {
                return [];
            }
        };

        $resolver = $this->createInspectableScalarResolver($input);
        $parameter = (new ReflectionMethod(ScalarResolverFixture::class, 'handleBoolSnake'))->getParameters()[0];

        self::assertTrue($resolver->resolve($parameter, 'bool'));
    }

    public function testResolveReturnsFalseForFalsyBoolValue(): void
    {
        $resolver = $this->createScalarResolver([
            'enabled' => 'no',
        ]);
        $parameter = (new ReflectionMethod(ScalarResolverFixture::class, 'handleBool'))->getParameters()[0];

        self::assertFalse($resolver->resolve($parameter, 'bool'));
    }

    public function testResolveReturnsArrayValueForArrayType(): void
    {
        $resolver = $this->createScalarResolver([
            'filters' => ['a', 'b'],
        ]);
        $parameter = (new ReflectionMethod(ScalarResolverFixture::class, 'handleArray'))->getParameters()[0];

        self::assertSame(['a', 'b'], $resolver->resolve($parameter, 'array'));
    }

    public function testResolveUsesPositionalArgumentsForUnboundParameters(): void
    {
        $resolver = $this->createScalarResolver([], ['first', 'second']);
        $method = new ReflectionMethod(ScalarResolverFixture::class, 'handlePositional');

        $parameters = $method->getParameters();

        self::assertSame('first', $resolver->resolve($parameters[0], 'string'));
        self::assertSame('second', $resolver->resolve($parameters[1], 'string'));
    }

    public function testResolveUsesArrayTailForPositionalArrayParameter(): void
    {
        $resolver = $this->createScalarResolver([], ['first', 'second', 'third']);
        $method = new ReflectionMethod(ScalarResolverFixture::class, 'handlePositionalArray');

        $parameters = $method->getParameters();

        self::assertSame('first', $resolver->resolve($parameters[0], 'string'));
        self::assertSame(['second', 'third'], $resolver->resolve($parameters[1], 'array'));
    }

    public function testResolveReturnsNullForArrayParameterWhenPositionalTailIsEmpty(): void
    {
        $resolver = $this->createScalarResolver([], ['onlyFirst']);
        $method = new ReflectionMethod(ScalarResolverFixture::class, 'handlePositionalArray');

        $parameters = $method->getParameters();

        self::assertSame('onlyFirst', $resolver->resolve($parameters[0], 'string'));
        self::assertNull($resolver->resolve($parameters[1], 'array'));
    }

    public function testResolveTreatsEmptyStringInputAsTrueForBoolParameter(): void
    {
        $resolver = $this->createScalarResolver([
            'enabled' => '',
        ]);
        $parameter = (new ReflectionMethod(ScalarResolverFixture::class, 'handleBool'))->getParameters()[0];

        self::assertTrue($resolver->resolve($parameter, 'bool'));
    }

    public function testResolveReturnsNullWhenPositionalListEndsBeforeLaterStringParameter(): void
    {
        $resolver = $this->createScalarResolver([], ['only']);
        $method = new ReflectionMethod(ScalarResolverFixture::class, 'handleThreeStrings');
        $parameters = $method->getParameters();

        self::assertSame('only', $resolver->resolve($parameters[0], 'string'));
        self::assertNull($resolver->resolve($parameters[1], 'string'));
        self::assertNull($resolver->resolve($parameters[2], 'string'));
    }

    public function testResolvePositionalSkipsObjectParameterWhenBuildingUnboundList(): void
    {
        $resolver = $this->createScalarResolver([], ['left', 'right']);
        $method = new ReflectionMethod(ScalarResolverFixture::class, 'handleObjectSandwich');
        $parameters = $method->getParameters();

        self::assertSame('left', $resolver->resolve($parameters[0], 'string'));
        self::assertSame('right', $resolver->resolve($parameters[2], 'string'));
    }

    public function testResolveReturnsNullWhenBoolInputAbsentWithoutFlags(): void
    {
        $input = new class () implements InputInterface {
            public function has(string|int $name): bool
            {
                return false;
            }

            public function get(string|int $name, mixed $default = null): mixed
            {
                return $default;
            }

            public function all(): array
            {
                return [];
            }
        };

        $resolver = $this->createInspectableScalarResolver($input);
        $parameter = (new ReflectionMethod(ScalarResolverFixture::class, 'handleBool'))->getParameters()[0];

        self::assertNull($resolver->resolve($parameter, 'bool'));
    }

    public function testResolveReturnsNullWhenParameterListCannotBeLocated(): void
    {
        $resolver = new class () extends ScalarResolver {
            public function setInput(InputInterface $input): void
            {
                $this->input = $input;
            }

            protected function extractParameters(ReflectionParameter $parameter): array
            {
                return [];
            }
        };

        $resolver->setInput(new class () implements InputInterface, PositionalInputInterface {
            public function has(string|int $name): bool
            {
                return false;
            }

            public function get(string|int $name, mixed $default = null): mixed
            {
                return $default;
            }

            public function all(): array
            {
                return [];
            }

            public function getPositional(): array
            {
                return ['only'];
            }

            public function getTrailing(): ?array
            {
                return null;
            }
        });

        $parameter = (new ReflectionMethod(ScalarResolverFixture::class, 'handle'))->getParameters()[0];

        self::assertNull($resolver->resolve($parameter, 'string'));
    }

    public function testFindParameterIndexFallsBackToIdentityAndName(): void
    {
        $resolver = $this->createInspectableScalarResolver();

        $positionParameters = (new ReflectionMethod(ScalarResolverFixture::class, 'handlePositional'))->getParameters();
        $namedParameter = (new ReflectionMethod(ScalarResolverFixture::class, 'handleSingle'))->getParameters()[0];

        self::assertSame(
            1,
            $resolver->findIndex([$positionParameters[1], $positionParameters[0]], $positionParameters[0])
        );
        self::assertSame(
            1,
            $resolver->findIndex([$positionParameters[1], $positionParameters[0]], $namedParameter)
        );
    }

    public function testResolveReturnsNullWhenPositionalAccessIsUnavailable(): void
    {
        $input = new class ([]) implements InputInterface {
            public function __construct(protected array $named)
            {
            }

            public function has(string|int $name): bool
            {
                return array_key_exists($name, $this->named);
            }

            public function get(string|int $name, mixed $default = null): mixed
            {
                return array_key_exists($name, $this->named) ? $this->named[$name] : $default;
            }

            public function all(): array
            {
                return $this->named;
            }
        };

        $resolver = $this->createInspectableScalarResolver($input);
        $parameter = (new ReflectionMethod(ScalarResolverFixture::class, 'handleSingle'))->getParameters()[0];

        self::assertNull($resolver->resolve($parameter, 'string'));
    }

    public function testResolveTruthyBoolFromTextualValues(): void
    {
        foreach (['1', 'yes', 'on', 'true'] as $truthy) {
            $resolver = $this->createScalarResolver([
                'enabled' => $truthy,
            ]);
            $parameter = (new ReflectionMethod(ScalarResolverFixture::class, 'handleBool'))->getParameters()[0];

            self::assertTrue($resolver->resolve($parameter, 'bool'), 'failed for value: ' . $truthy);
        }
    }

    public function testFindParameterIndexReturnsNullWhenParameterNotInList(): void
    {
        $resolver = $this->createInspectableScalarResolver();
        $parameter = (new ReflectionMethod(ScalarResolverFixture::class, 'handleSingle'))->getParameters()[0];

        self::assertNull($resolver->findIndex([], $parameter));
    }

    public function testResolveIgnoresGetPositionalWhenInputDoesNotImplementPositionalContract(): void
    {
        $input = new class () implements InputInterface {
            public function has(string|int $name): bool
            {
                return false;
            }

            public function get(string|int $name, mixed $default = null): mixed
            {
                return $default;
            }

            public function all(): array
            {
                return [];
            }

            public function getPositional(): array
            {
                return ['ignored'];
            }
        };

        $resolver = $this->createInspectableScalarResolver($input);
        $parameter = (new ReflectionMethod(ScalarResolverFixture::class, 'handlePositional'))->getParameters()[0];

        self::assertNull($resolver->resolve($parameter, 'string'));
    }

    public function testResolveThrowsOnTooManyPositionalArguments(): void
    {
        $resolver = $this->createScalarResolver([], ['first', 'second']);
        $parameter = (new ReflectionMethod(ScalarResolverFixture::class, 'handleSingle'))->getParameters()[0];

        $this->expectException(TooManyPositionalArgumentsException::class);
        $resolver->resolve($parameter, 'string');
    }

    public function testTrailingFillsLastArrayWithoutStealingOptionalScalars(): void
    {
        $resolver = $this->createScalarResolver([], ['app'], ['npx', '-y', 'tool']);
        $parameters = (new ReflectionMethod(ScalarResolverFixture::class, 'handleOptionalThenArray'))->getParameters();

        self::assertSame('app', $resolver->resolve($parameters[0], 'string'));
        self::assertNull($resolver->resolve($parameters[1], 'bool'));
        self::assertNull($resolver->resolve($parameters[2], 'string'));
        self::assertSame(['npx', '-y', 'tool'], $resolver->resolve($parameters[3], 'array'));
    }

    public function testTrailingEmptyLeavesArrayNullForDefault(): void
    {
        $resolver = $this->createScalarResolver([], ['app'], []);
        $parameters = (new ReflectionMethod(ScalarResolverFixture::class, 'handleOptionalThenArray'))->getParameters();

        self::assertSame('app', $resolver->resolve($parameters[0], 'string'));
        self::assertNull($resolver->resolve($parameters[3], 'array'));
    }

    public function testNamedForceWithTrailingDoesNotConsumeCommand(): void
    {
        $resolver = $this->createScalarResolver(['force' => true], ['app'], ['npx', '-y', 'tool']);
        $parameters = (new ReflectionMethod(ScalarResolverFixture::class, 'handleOptionalThenArray'))->getParameters();

        self::assertSame('app', $resolver->resolve($parameters[0], 'string'));
        self::assertTrue($resolver->resolve($parameters[1], 'bool'));
        self::assertNull($resolver->resolve($parameters[2], 'string'));
        self::assertSame(['npx', '-y', 'tool'], $resolver->resolve($parameters[3], 'array'));
    }

    public function testNamedUrlWithTrailingLeavesCommandIntact(): void
    {
        $resolver = $this->createScalarResolver(
            ['url' => 'https://example.com/mcp'],
            ['docs'],
            ['should', 'stay'],
        );
        $parameters = (new ReflectionMethod(ScalarResolverFixture::class, 'handleOptionalThenArray'))->getParameters();

        self::assertSame('docs', $resolver->resolve($parameters[0], 'string'));
        self::assertNull($resolver->resolve($parameters[1], 'bool'));
        self::assertSame('https://example.com/mcp', $resolver->resolve($parameters[2], 'string'));
        self::assertSame(['should', 'stay'], $resolver->resolve($parameters[3], 'array'));
    }

    public function testWithoutTrailingOptionalScalarsStealRemainingPositionals(): void
    {
        $resolver = $this->createScalarResolver([], ['app', 'npx', 'pkg', 'extra'], null);
        $parameters = (new ReflectionMethod(ScalarResolverFixture::class, 'handleOptionalThenArray'))->getParameters();

        self::assertSame('app', $resolver->resolve($parameters[0], 'string'));
        self::assertSame('npx', $resolver->resolve($parameters[1], 'bool')); // raw positional into bool slot
        self::assertSame('pkg', $resolver->resolve($parameters[2], 'string'));
        self::assertSame(['extra'], $resolver->resolve($parameters[3], 'array'));
    }

    public function testTrailingAloneFillsArrayWhenNoPositionals(): void
    {
        $resolver = $this->createScalarResolver([], [], ['only', 'cmd']);
        $parameters = (new ReflectionMethod(ScalarResolverFixture::class, 'handleOptionalThenArray'))->getParameters();

        self::assertNull($resolver->resolve($parameters[0], 'string'));
        self::assertNull($resolver->resolve($parameters[1], 'bool'));
        self::assertNull($resolver->resolve($parameters[2], 'string'));
        self::assertSame(['only', 'cmd'], $resolver->resolve($parameters[3], 'array'));
    }

    public function testTrailingDoesNotApplyWhenLastUnboundIsNotArray(): void
    {
        $resolver = $this->createScalarResolver([], ['a'], ['ignored']);
        $parameters = (new ReflectionMethod(ScalarResolverFixture::class, 'handlePositional'))->getParameters();

        self::assertSame('a', $resolver->resolve($parameters[0], 'string'));
        self::assertNull($resolver->resolve($parameters[1], 'string'));
    }

    public function testTrailingPreferredOverLeftoverPositionalsForArray(): void
    {
        // Extra left-side tokens after server would historically feed the array;
        // with `--`, trailing wins exclusively.
        $resolver = $this->createScalarResolver([], ['app', 'left-over'], ['npx', 'tool']);
        $parameters = (new ReflectionMethod(ScalarResolverFixture::class, 'handlePositionalArray'))->getParameters();

        self::assertSame('app', $resolver->resolve($parameters[0], 'string'));
        self::assertSame(['npx', 'tool'], $resolver->resolve($parameters[1], 'array'));
    }

    public function testNoTrailingStillUsesRemainingPositionalsForArray(): void
    {
        $resolver = $this->createScalarResolver([], ['app', 'npx', 'tool'], null);
        $parameters = (new ReflectionMethod(ScalarResolverFixture::class, 'handlePositionalArray'))->getParameters();

        self::assertSame('app', $resolver->resolve($parameters[0], 'string'));
        self::assertSame(['npx', 'tool'], $resolver->resolve($parameters[1], 'array'));
    }

    /**
     * @param array<string, mixed> $named
     * @param list<string> $positional
     * @param list<string>|null $trailing
     * @param list<mixed> $expected server, force, url, command (null = unresolved)
     */
    #[DataProvider('trailingBindMatrixProvider')]
    public function testTrailingBindMatrix(
        array $named,
        array $positional,
        ?array $trailing,
        array $expected,
    ): void {
        $resolver = $this->createScalarResolver($named, $positional, $trailing);
        $parameters = (new ReflectionMethod(ScalarResolverFixture::class, 'handleOptionalThenArray'))->getParameters();

        self::assertSame($expected[0], $resolver->resolve($parameters[0], 'string'));
        self::assertSame($expected[1], $resolver->resolve($parameters[1], 'bool'));
        self::assertSame($expected[2], $resolver->resolve($parameters[2], 'string'));
        self::assertSame($expected[3], $resolver->resolve($parameters[3], 'array'));
    }

    /**
     * @return iterable<string, array{
     *   0: array<string, mixed>,
     *   1: list<string>,
     *   2: list<string>|null,
     *   3: list<mixed>
     * }>
     */
    public static function trailingBindMatrixProvider(): iterable
    {
        yield 'separator protects command from force/url defaults' => [
            [],
            ['app'],
            ['npx', '-y', 'tool'],
            ['app', null, null, ['npx', '-y', 'tool']],
        ];
        yield 'named force true with separator' => [
            ['force' => true],
            ['app'],
            ['echo'],
            ['app', true, null, ['echo']],
        ];
        yield 'named url empty string is bound' => [
            ['url' => ''],
            ['docs'],
            null,
            ['docs', null, '', null],
        ];
        yield 'separator with option-looking trailing' => [
            [],
            ['app'],
            ['--force', '--url=x'],
            ['app', null, null, ['--force', '--url=x']],
        ];
        yield 'empty trailing nulls array for default' => [
            ['force' => true],
            ['app'],
            [],
            ['app', true, null, null],
        ];
        yield 'no argv at all' => [
            [],
            [],
            null,
            [null, null, null, null],
        ];
    }

    protected function createScalarResolver(array $named = [], array $positional = [], ?array $trailing = null): ScalarResolver
    {
        return $this->createInspectableScalarResolver(
            new class ($named, $positional, $trailing) implements
                InputInterface,
                PositionalInputInterface {
                public function __construct(
                    protected array $named,
                    protected array $positional,
                    protected ?array $trailing,
                ) {
                }

                public function has(string|int $name): bool
                {
                    return array_key_exists($name, $this->named);
                }

                public function get(string|int $name, mixed $default = null): mixed
                {
                    return array_key_exists($name, $this->named) ? $this->named[$name] : $default;
                }

                public function all(): array
                {
                    return $this->named;
                }

                public function getPositional(): array
                {
                    return $this->positional;
                }

                public function getTrailing(): ?array
                {
                    return $this->trailing;
                }
            }
        );
    }

    protected function createInspectableScalarResolver(?InputInterface $input = null): ScalarResolver
    {
        $resolver = new class () extends ScalarResolver {
            public function setInput(InputInterface $input): void
            {
                $this->input = $input;
            }

            /**
             * @param array<int, ReflectionParameter> $parameters
             */
            public function findIndex(array $parameters, ReflectionParameter $current): ?int
            {
                return $this->findParameterIndex($parameters, $current);
            }
        };

        if ($input !== null) {
            $resolver->setInput($input);
        }

        return $resolver;
    }
}

class ScalarPlainObject
{
}

class ScalarResolverFixture
{
    public function handle(string $name): void
    {
    }

    public function handleSnake(string $postId): void
    {
    }

    public function handlePositional(string $first, string $second): void
    {
    }

    public function handleSingle(string $first): void
    {
    }

    public function handleArrayValue(string $name): void
    {
    }

    public function handleBool(bool $enabled): void
    {
    }

    public function handleBoolSnake(bool $acceptTerms): void
    {
    }

    public function handleArray(array $filters): void
    {
    }

    public function handlePositionalArray(string $first, array $rest): void
    {
    }

    public function handleOptionalThenArray(string $server, bool $force = false, string $url = '', array $command = []): void
    {
    }

    public function handleThreeStrings(string $a, string $b, string $c): void
    {
    }

    public function handleObjectSandwich(string $first, ScalarPlainObject $middle, string $last): void
    {
    }
}
