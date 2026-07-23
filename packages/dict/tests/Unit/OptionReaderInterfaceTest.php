<?php

declare(strict_types=1);

namespace Switon\Dict\Tests\Unit;

use Switon\Dict\OptionReaderInterface;
use PHPUnit\Framework\TestCase;

class OptionReaderInterfaceTest extends TestCase
{
    public function testOptionRowsFollowTheDocumentedShape(): void
    {
        $reader = new class () implements OptionReaderInterface {
            public function getOptions(string $typeCode, bool $builtin = true): array
            {
                return [
                    [
                        'label' => 'Pending',
                        'value' => 'pending',
                        'code' => 'pending',
                        'tag' => 10,
                        'default' => true,
                    ],
                ];
            }
        };

        $options = $reader->getOptions('order.status');

        $this->assertIsArray($options);
        $this->assertCount(1, $options);
        $this->assertSame(
            [
                'label' => 'Pending',
                'value' => 'pending',
                'code' => 'pending',
                'tag' => 10,
                'default' => true,
            ],
            $options[0]
        );
        $this->assertIsString($options[0]['label']);
        $this->assertIsString($options[0]['value']);
        $this->assertIsString($options[0]['code']);
        $this->assertIsInt($options[0]['tag']);
        $this->assertIsBool($options[0]['default']);
    }
}
