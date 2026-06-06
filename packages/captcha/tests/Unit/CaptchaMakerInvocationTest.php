<?php

declare(strict_types=1);

namespace Switon\Captcha\Tests\Unit;

use Switon\Captcha\Adapter\Gd;
use Switon\Captcha\Adapter\Imagick;
use Switon\Captcha\Captcha;
use Switon\Captcha\CaptchaInterface;
use Switon\Captcha\CaptchaProof;
use Switon\Captcha\Tests\TestCase;
use Switon\Core\MakerInterface;

use function extension_loaded;

final class CaptchaMakerInvocationTest extends TestCase
{
    public function testFactoryUsesMakerWithParameters(): void
    {
        $expectedParameters = ['length' => 6];

        $calls = [];
        $maker = new class ($calls) implements MakerInterface {
            /** @var array<int, array{name: string, parameters: array<string, mixed>}> */
            public array $calls;

            public function __construct(array &$calls)
            {
                $this->calls = &$calls;
            }

            public function make(string $name, array $parameters = []): mixed
            {
                $this->calls[] = ['name' => $name, 'parameters' => $parameters];

                return new class () implements CaptchaInterface {
                    public function create(int $width = 120, int $height = 40, int $ttl = 300, ?string $tag = null): array
                    {
                        return ['key' => 'k', 'blob' => 'b'];
                    }

                    public function verify(CaptchaProof $proof, ?string $tag = null): void
                    {
                    }

                    public function draw(string $code, int $width, int $height): string
                    {
                        return '';
                    }
                };
            }
        };

        $factory = new CaptchaMakerInvocationFixture($maker);

        $result = $factory($expectedParameters);

        $this->assertInstanceOf(CaptchaInterface::class, $result);
        $this->assertCount(1, $calls);
        $this->assertSame($expectedParameters, $calls[0]['parameters']);

        if (extension_loaded('imagick')) {
            $this->assertSame(Imagick::class, $calls[0]['name']);
            return;
        }

        if (extension_loaded('gd')) {
            $this->assertSame(Gd::class, $calls[0]['name']);
            return;
        }

        $this->markTestSkipped('Neither ImageMagick nor GD extension is available');
    }
}

final class CaptchaMakerInvocationFixture extends Captcha
{
    public function __construct(MakerInterface $maker)
    {
        $this->maker = $maker;
    }
}
