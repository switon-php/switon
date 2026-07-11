<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Unit\ValueResolver;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\Core\InputInterface;
use Switon\Http\Attribute\RequestBody;
use Switon\Http\RequestInterface;
use Switon\Http\Tests\Support\BoundAction;
use Switon\Http\Tests\TestCase;
use Switon\Validating\Attribute\ArrayOf;
use Switon\Validating\Attribute\Length;
use Switon\Validating\Attribute\Min;
use Switon\Validating\Exception\ValidateFailedException;

/**
 * Verify that validation errors cause exception to be thrown.
 */
#[AllowMockObjectsWithoutExpectations]
class ArrayValidationThrowsTest extends TestCase
{
    public function testArrayElementValidationThrowsException(): void
    {
        $inputData = [
            'title' => 'Test Order',
            'items' => [
                ['name' => 'Valid Item', 'qty' => 5],
                ['name' => 'X', 'qty' => 2],
                ['name' => 'Another Valid', 'qty' => -1],
            ],
        ];

        $input = $this->createMock(InputInterface::class);
        $input->method('all')->willReturn($inputData);
        $input->method('get')->willReturnCallback(fn ($key) => $inputData[$key] ?? null);
        $input->method('has')->willReturnCallback(fn ($key) => array_key_exists($key, $inputData));

        $request = $this->createMock(RequestInterface::class);
        $request->method('post')->willReturn($inputData);

        $this->container->set(InputInterface::class, $input);
        $this->container->set(RequestInterface::class, $request);

        $controller = new TestOrderController();

        $this->expectException(ValidateFailedException::class);

        try {
            BoundAction::invoke($this->container, $controller, 'createAction');
            $this->fail('Expected ValidateFailedException to be thrown');
        } catch (ValidateFailedException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('items.1.name', $errors);
            $this->assertArrayHasKey('items.2.qty', $errors);
            throw $e;
        }
    }
}

#[RequestBody]
class TestOrderRequest
{
    public string $title;

    #[ArrayOf(TestOrderItemInput::class, minItems: 1)]
    public array $items;
}

class TestOrderItemInput
{
    #[Length(2, 100)]
    public string $name;

    #[Min(1)]
    public int $qty;
}

class TestOrderController
{
    public function createAction(TestOrderRequest $request): array
    {
        return ['success' => true];
    }
}
