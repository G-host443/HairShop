<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Settings\SettingsSchema;
use PHPUnit\Framework\TestCase;

final class SettingsSchemaTest extends TestCase
{
    private static function noMedia(): callable
    {
        return static fn (int $id): bool => $id === 7;
    }

    public function testEveryFieldBelongsToAKnownGroupAndHasAValidType(): void
    {
        $types = ['text', 'textarea', 'email', 'tel', 'whatsapp', 'url', 'bool', 'int', 'select', 'media', 'timezone'];

        foreach (SettingsSchema::fields() as $key => $field) {
            self::assertArrayHasKey($field['group'], SettingsSchema::GROUPS, $key);
            self::assertContains($field['type'], $types, $key);
            self::assertStringStartsWith($field['group'] . '.', $key, 'La clé commence par son groupe.');
            if ($field['type'] === 'int') {
                self::assertArrayHasKey('min', $field, $key);
                self::assertArrayHasKey('max', $field, $key);
            }
        }
    }

    public function testUrlsMustBeHttp(): void
    {
        $result = SettingsSchema::validateGroup('social', [
            'social__instagram' => 'https://www.instagram.com/garance',
            'social__facebook' => 'javascript:alert(1)',
            'social__tiktok' => '',
        ], self::noMedia());

        self::assertSame('https://www.instagram.com/garance', $result['values']['social.instagram']);
        self::assertSame('', $result['values']['social.tiktok']);
        self::assertArrayHasKey('social__facebook', $result['errors']);
    }

    public function testMediaFieldUsesTheProvidedCheck(): void
    {
        $base = ['general__site_name' => 'X', 'general__currency' => 'EUR', 'general__timezone' => 'Europe/Paris', 'general__locale' => 'fr_FR'];

        $ok = SettingsSchema::validateGroup('general', $base + ['general__logo_media_id' => '7'], self::noMedia());
        self::assertSame(7, $ok['values']['general.logo_media_id']);

        $ko = SettingsSchema::validateGroup('general', $base + ['general__logo_media_id' => '8'], self::noMedia());
        self::assertArrayHasKey('general__logo_media_id', $ko['errors']);

        $empty = SettingsSchema::validateGroup('general', $base, self::noMedia());
        self::assertNull($empty['values']['general.logo_media_id']);
    }

    public function testTimezoneMustExist(): void
    {
        $result = SettingsSchema::validateGroup('general', [
            'general__site_name' => 'X', 'general__currency' => 'EUR', 'general__timezone' => 'Mars/Olympus', 'general__locale' => 'fr_FR',
        ], self::noMedia());

        self::assertArrayHasKey('general__timezone', $result['errors']);
    }
}
