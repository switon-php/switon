<?php

declare(strict_types=1);

namespace Switon\Query\PhpStan;

use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use Switon\Query\Table;

class ShardingManagerWidenedParametersAcceptor implements ParametersAcceptor
{
    public function __construct(protected ParametersAcceptor $inner)
    {
    }

    public function getTemplateTypeMap(): TemplateTypeMap
    {
        return $this->inner->getTemplateTypeMap();
    }

    public function getResolvedTemplateTypeMap(): TemplateTypeMap
    {
        return $this->inner->getResolvedTemplateTypeMap();
    }

    public function getParameters(): array
    {
        $parameters = $this->inner->getParameters();
        $widened = [];
        foreach ($parameters as $parameter) {
            if ($parameter->getName() !== 'table') {
                $widened[] = $parameter;
                continue;
            }
            $widened[] = new ShardingManagerWidenedParameterReflection(
                $parameter,
                new UnionType([$parameter->getType(), new ObjectType(Table::class)]),
            );
        }

        return $widened;
    }

    public function isVariadic(): bool
    {
        return $this->inner->isVariadic();
    }

    public function getReturnType(): Type
    {
        return $this->inner->getReturnType();
    }
}
