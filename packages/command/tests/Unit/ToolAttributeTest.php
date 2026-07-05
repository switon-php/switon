<?php

declare(strict_types=1);

namespace Switon\Command\Tests\Unit;

use Switon\Command\CommandInspector;
use Switon\Command\Tests\TestCase;

class ToolAttributeTest extends TestCase
{
    public function testToolDocExposedThroughInspector(): void
    {
        $inspector = $this->make(CommandInspector::class);

        $this->assertSame(
            'Returns JSON: {"ok":true}',
            $inspector->getActionAiDoc(ToolSample::class, 'action'),
        );
    }
}

class ToolSample
{
    #[\Switon\Command\Attribute\Tool('Returns JSON: {"ok":true}')]
    public function actionAction(): void
    {
    }
}
