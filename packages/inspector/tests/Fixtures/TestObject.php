<?php

declare(strict_types=1);

namespace Switon\Inspector\Tests\Fixtures;

use stdClass;

class TestObject
{
    public string $publicProperty = 'public';
    protected string $protectedProperty = 'protected';
    private string $privateProperty = 'private';
    public static string $staticProperty = 'static';
    public ?string $nullProperty = null;
    public array $arrayProperty = ['key' => 'value'];
    public object $objectProperty;

    public function __construct()
    {
        $this->objectProperty = new stdClass();
        $this->objectProperty->nested = 'nested';
    }
}
