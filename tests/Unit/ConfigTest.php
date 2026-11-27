<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDotNotationAccess(): void
    {
        $config = new Config(['app' => ['name' => 'Garance', 'debug' => false]]);

        self::assertSame('Garance', $config->get('app.name'));
        self::assertFalse($config->get('app.debug'));
        self::assertSame('défaut', $config->get('app.inconnu', 'défaut'));
        self::assertTrue($config->has('app.debug'));
        self::assertFalse($config->has('app.inconnu'));
    }

    public function testSetCreatesNestedKeys(): void
    {
        $config = new Config();
        $config->set('booking.cancel.hours', 48);

        self::assertSame(48, $config->get('booking.cancel.hours'));
    }
}
