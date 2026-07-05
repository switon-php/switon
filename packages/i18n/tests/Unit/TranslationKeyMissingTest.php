<?php

declare(strict_types=1);

namespace Switon\I18n\Tests\Unit;

use Switon\I18n\Event\TranslationKeyMissing;
use PHPUnit\Framework\TestCase;

/**
 * Tests the missing-translation event payload.
 */
class TranslationKeyMissingTest extends TestCase
{
    /**
     * Test that event stores key and effective locale.
     */
    public function testEventStoresKeyAndLocale(): void
    {
        // Act
        $sender = $this->createStub(\Switon\Core\TranslatorInterface::class);
        $event = new TranslationKeyMissing($sender, key: 'user.missing', locale: 'zh-cn');

        // Assert
        $this->assertSame('user.missing', $event->key);
        $this->assertSame('zh-cn', $event->locale);
    }

    /**
     * Test that jsonSerialize() returns key and locale payload.
     */
    public function testJsonSerializeReturnsKeyAndLocale(): void
    {
        // Arrange
        $sender = $this->createStub(\Switon\Core\TranslatorInterface::class);
        $event = new TranslationKeyMissing($sender, key: 'user.missing', locale: 'en');

        // Act
        $payload = $event->jsonSerialize();

        // Assert
        $this->assertSame([
            'key' => 'user.missing',
            'locale' => 'en',
        ], $payload);
    }
}
