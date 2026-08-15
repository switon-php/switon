<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Tests\TestCase;
use Switon\Ai\Signal\UserSignal;

#[CoversClass(UserSignal::class)]
class UserSignalTest extends TestCase
{
    public function testConstructsGenericSignalEnvelope(): void
    {
        $message = new UserSignal(
            'skill_load',
            ['id' => 'demo'],
            'Loaded skill.',
        );

        $this->assertSame('skill_load', $message->name);
        $this->assertSame('demo', $message->fields['id']);
        $this->assertSame('Loaded skill.', $message->body);
        $this->assertSame('skill_load', $message->label());
        $this->assertSame('[skill_load] Loaded skill.', $message->text());
        $this->assertStringContainsString('[signal:skill_load]', $message->wire()[0]['text']);
    }

    public function testJsonSerializeShape(): void
    {
        $message = new UserSignal('demo', ['key' => 'value'], 'body');
        $json = $message->jsonSerialize();

        $this->assertSame(UserSignal::class, $json['class']);
        $this->assertSame('demo', $json['name']);
        $this->assertSame('value', $json['fields']['key']);
        $this->assertSame('body', $json['body']);
        $this->assertArrayNotHasKey('headline', $json);
    }
}
