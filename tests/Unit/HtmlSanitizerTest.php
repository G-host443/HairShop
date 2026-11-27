<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Helpers\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class HtmlSanitizerTest extends TestCase
{
    public function testKeepsAllowedFormatting(): void
    {
        $html = '<h2>Entretien</h2><p>Un <strong>foulard</strong> en <em>satin</em>, <u>chaque</u> soir.</p>'
            . '<ul><li>Huile</li><li>Spray</li></ul><ol><li>Un</li></ol><blockquote>Conseil</blockquote>'
            . '<p><a href="/reserver">Réserver</a></p>';

        self::assertSame($html, HtmlSanitizer::clean($html));
    }

    public function testNormalisesEditorOutput(): void
    {
        // Quill 2 : listes à puces en <ol data-list="bullet">, interface .ql-ui, paragraphes vides de fin.
        $quill = '<h1>Titre</h1><ol><li data-list="bullet"><span class="ql-ui" contenteditable="false"></span>Un</li></ol>'
            . '<ol><li data-list="ordered"><span class="ql-ui"></span>Deux</li></ol><p><b>Gras</b> <i>it</i></p><p><br></p>';

        self::assertSame('<h2>Titre</h2><ul><li>Un</li></ul><ol><li>Deux</li></ol><p><strong>Gras</strong> <em>it</em></p>', HtmlSanitizer::clean($quill));
    }

    public function testRemovesEverythingDangerous(): void
    {
        $evil = '<p onclick="steal()" style="color:red" class="x">Texte</p>'
            . '<script>alert(1)</script><style>body{}</style><iframe src="https://evil"></iframe>'
            . '<a href="javascript:alert(1)">lien piégé</a><a href=" JaVaScRiPt:alert(1)">bis</a><a href="//evil.test/x">relatif réseau</a>'
            . '<a href="data:text/html;base64,PHNjcmlwdD4=">data</a>'
            . '<img src="https://evil.test/track.gif"><img src="/uploads/../../etc/passwd.jpg"><img src="javascript:x">'
            . '<svg><script>alert(2)</script></svg><math><mi>x</mi></math>'
            . '<p>&lt;script&gt;alert(3)&lt;/script&gt;</p><!-- commentaire -->';

        $clean = HtmlSanitizer::clean($evil);

        self::assertSame('<p>Texte</p>lien piégébisrelatif réseaudata<p>&lt;script&gt;alert(3)&lt;/script&gt;</p>', $clean);
    }

    public function testLinksAndImages(): void
    {
        self::assertSame(
            '<p><a href="https://instagram.com/x" target="_blank" rel="noopener noreferrer">Instagram</a> <a href="mailto:a@b.fr">mail</a></p>',
            HtmlSanitizer::clean('<p><a href="https://instagram.com/x" target="_self" onclick="x">Instagram</a> <a href="mailto:a@b.fr">mail</a></p>'),
        );
        self::assertSame(
            '<p><img src="/uploads/2027/03/photo-medium.webp" alt="Knotless &quot;mi-dos&quot;" loading="lazy" data-media-id="12"></p>',
            HtmlSanitizer::clean('<p><img src="/uploads/2027/03/photo-medium.webp" alt=\'Knotless "mi-dos"\' data-media-id="12" onerror="x" width="900"></p>'),
        );
        self::assertSame('', HtmlSanitizer::clean('<p><br></p>'));
    }

    public function testToText(): void
    {
        self::assertSame("Titre\nUn & deux", HtmlSanitizer::toText('<h2>Titre</h2><p>Un &amp; deux</p>'));
    }
}
