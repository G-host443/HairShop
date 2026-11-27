<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Helpers\SimpleMarkup;
use PHPUnit\Framework\TestCase;

final class SimpleMarkupTest extends TestCase
{
    public function testFormatting(): void
    {
        $html = SimpleMarkup::toHtml("## Avant le rendez-vous\nVenez les cheveux **démêlés** et *propres*.\n\n- Mèches fournies\n- Prévoir 4 h\n\nLigne 1\nLigne 2");

        self::assertSame(
            "<h3>Avant le rendez-vous</h3>\n<p>Venez les cheveux <strong>démêlés</strong> et <em>propres</em>.</p>\n<ul><li>Mèches fournies</li><li>Prévoir 4 h</li></ul>\n<p>Ligne 1<br>Ligne 2</p>",
            $html,
        );
    }

    public function testLinks(): void
    {
        self::assertSame('<p>Voir <a href="/contact">la page contact</a></p>', SimpleMarkup::toHtml('Voir [la page contact](/contact)'));
        self::assertSame(
            '<p><a href="https://instagram.com/x" target="_blank" rel="noopener">Instagram</a></p>',
            SimpleMarkup::toHtml('[Instagram](https://instagram.com/x)'),
        );
        self::assertSame('<p><a href="mailto:a@b.fr">écrire</a></p>', SimpleMarkup::toHtml('[écrire](mailto:a@b.fr)'));
    }

    public function testNothingCanBeInjected(): void
    {
        $html = SimpleMarkup::toHtml('<script>alert(1)</script> [clic](javascript:alert(1)) [x](//evil.test) <img src=x onerror=alert(1)> [a"b](/ok"onmouseover="x)');

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('href="javascript', $html);
        self::assertStringNotContainsString('href="//evil', $html);
        self::assertStringNotContainsString('onmouseover="', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testPlainText(): void
    {
        self::assertSame("Titre\nUn lien", SimpleMarkup::toPlainText("## Titre\nUn **[lien](/x)**"));
    }
}
