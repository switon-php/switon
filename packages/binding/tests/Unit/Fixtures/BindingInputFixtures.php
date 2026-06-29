<?php

declare(strict_types=1);

namespace Switon\Binding\Tests\Unit\Fixtures;

use Attribute;
use ReflectionProperty;
use Switon\Core\Attribute\Autowired;
use Switon\Validating\Attribute\ArrayOf;
use Switon\Validating\Attribute\Date;
use Switon\Validating\Attribute\NotEmpty;
use Switon\Validating\Attribute\Required;
use Switon\Validating\Attribute\Type;

class BindingInputFixtures
{
    public string $child = 'default-child';

    public BindingNestedInput $nested;

    public ?BindingNestedInput $peer = null;

    #[ArrayOf(type: BindingNestedInput::class, minItems: 2, maxItems: 3)]
    public array $nestedList = [];

    #[ArrayOf(type: 'string', minItems: 1)]
    public array $labels = [];

    #[ArrayOf(type: BindingNestedInput::class)]
    public array $looseNestedList = [];

    #[ArrayOf(type: 'int')]
    public array $scores = [];

    #[ArrayOf(type: 'string')]
    public ?array $optionalList = null;

    #[Required]
    public ?string $requiredNullableLabel;

    public function __construct()
    {
        $this->nested = new BindingNestedInput();
    }
}

class BindingNestedInput
{
    public string $value = 'default-nested';
}

class BindingTagsInputFixture
{
    /**
     * @var list<string>
     */
    #[ArrayOf(type: 'string', minItems: 2)]
    public array $tags = [];
}

class BindingDateInputFixture
{
    #[Date]
    public string $createdAt = '';
}

class BindingDateIntInputFixture
{
    #[Date]
    public int $createdAt = 0;
}

class BindingNotEmptyCodeFixture
{
    #[Type('string'), NotEmpty]
    public string $code;

    public function __construct()
    {
        $this->code = 'seed';
    }
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class UpperInputFixtureNormalizer
{
    public function normalizeInput(ReflectionProperty $property, mixed $value): mixed
    {
        return is_string($value) ? strtoupper($value) : $value;
    }
}

class BindingUpperTitleFixture
{
    public BindingNestedInput $nested;

    #[UpperInputFixtureNormalizer]
    public string $title = '';

    public function __construct()
    {
        $this->nested = new BindingNestedInput();
    }
}

class BindingRequiredListFixture
{
    #[ArrayOf(type: 'string')]
    public array $items;
}

class BindingSlugRequiredFixture
{
    public BindingNestedInput $nested;

    #[Required]
    public string $slug;

    public function __construct()
    {
        $this->nested = new BindingNestedInput();
    }
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class NullToNormalizer
{
    public function normalizeInput(ReflectionProperty $property, mixed $value): mixed
    {
        return null;
    }
}

class BindingNullNormalizedFixture
{
    public BindingNestedInput $nested;

    #[NullToNormalizer]
    public string $code = '';

    public function __construct()
    {
        $this->nested = new BindingNestedInput();
    }
}

class BindingAutowiredInputFixture
{
    #[Autowired] public \Switon\Validating\ValidatorInterface $validator;

    public string $value = '';
}

class BindingArrayConstructedBag
{
    /** @param array<string, mixed> $values */
    public function __construct(protected array $values = [])
    {
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}

class BindingArrayConstructedInputFixture
{
    public string $name = '';

    public ?BindingArrayConstructedBag $extra = null;
}
