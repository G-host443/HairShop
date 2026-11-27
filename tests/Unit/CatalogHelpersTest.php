<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Helpers\Slug;
use App\Services\Catalog\CatalogReader;
use App\Services\Catalog\Quote;
use PHPUnit\Framework\TestCase;

final class CatalogHelpersTest extends TestCase
{
    public function testSlugTransliteratesFrench(): void
    {
        self::assertSame('nattes-a-l-ancienne', Slug::make('Nattes à l\'ancienne'));
        self::assertSame('enfants-4-a-10-ans', Slug::make('Enfants (4 à 10 ans)'));
        self::assertSame('noeuds-en-finition', Slug::make('Nœuds en finition'));
        self::assertSame('element', Slug::make('!!!'));
        self::assertTrue(Slug::isValid('box-twist'));
        self::assertFalse(Slug::isValid('Box Twist'));
    }

    public function testPriceParsing(): void
    {
        self::assertSame('45.00', Slug::parsePrice('45'));
        self::assertSame('45.50', Slug::parsePrice('45,50'));
        self::assertSame('45.50', Slug::parsePrice('45.5 €'));
        self::assertSame('1250.00', Slug::parsePrice('1 250'));
        self::assertNull(Slug::parsePrice('abc'));
        self::assertNull(Slug::parsePrice('-5'));
        self::assertNull(Slug::parsePrice('4,555'));
    }

    public function testQuoteTotals(): void
    {
        $options = [
            ['id' => 1, 'label' => 'Perles', 'price' => '10.00', 'kind' => 'fixed', 'minutes' => 15],
            ['id' => 2, 'label' => 'Boucles', 'price' => '0.00', 'kind' => 'fixed', 'minutes' => 10],
        ];
        $quote = new Quote(1, 2, 3, 'Knotless taille M — Mi-dos', 'fixed', '100.00', $options, 405);

        self::assertSame('110.00', $quote->total());
        self::assertFalse($quote->isQuote());
        self::assertSame('Knotless taille M — Mi-dos + Perles + Boucles', $quote->fullLabel());

        $withQuote = new Quote(1, 2, 3, 'X', 'fixed', '100.00', [['id' => 3, 'label' => 'Mèches', 'price' => null, 'kind' => 'quote', 'minutes' => 0]], 300);
        self::assertTrue($withQuote->isQuote());
        self::assertNull($withQuote->total());

        $from = new Quote(1, null, null, 'Tissage', 'from', '55.00', [], 150);
        self::assertTrue($from->isFromPrice());
        self::assertSame('55.00', $from->total());
    }

    public function testDurationFormatting(): void
    {
        self::assertSame('45 min', CatalogReader::duration(45));
        self::assertSame('3 h', CatalogReader::duration(180));
        self::assertSame('6 h 05', CatalogReader::duration(365));
    }
}
