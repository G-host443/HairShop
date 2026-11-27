<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Theme\FontCatalog;
use App\Services\Theme\TemplateManifest;
use App\Services\Theme\ThemeTokens;
use PHPUnit\Framework\TestCase;

final class ThemeTokensTest extends TestCase
{
    private const DEFAULTS = [
        'color.bg' => '#F6EFDF',
        'color.accent' => '#DFB05E',
        'font.heading' => 'Cormorant Garamond',
        'font.body' => 'Jost',
        'radius.card' => '18px',
        'motion.level' => 'subtle',
    ];

    public function testOverridesReplaceOnlyValidDeclaredTokens(): void
    {
        $tokens = new ThemeTokens(self::DEFAULTS, [
            'color.accent' => '#B76E79',                        // valide → appliqué
            'color.bg' => 'red; } body { display:none',         // injection CSS → ignorée
            'font.body' => 'Comic Sans MS',                     // hors liste blanche → ignorée
            'color.inconnue' => '#000000',                      // non déclarée par le template → ignorée
            'radius.card' => '24px',
        ]);

        self::assertSame('#B76E79', $tokens->get('color.accent'));
        self::assertSame('#F6EFDF', $tokens->get('color.bg'));
        self::assertSame('Jost', $tokens->get('font.body'));
        self::assertNull($tokens->get('color.inconnue'));
        self::assertSame('24px', $tokens->get('radius.card'));
    }

    public function testCssOutputContainsVariablesAndRgbVariants(): void
    {
        $css = (new ThemeTokens(self::DEFAULTS))->toCss();

        self::assertStringContainsString('--color-bg: #F6EFDF;', $css);
        self::assertStringContainsString('--color-accent-rgb: 223 176 94;', $css);
        self::assertStringContainsString('--font-heading: "Cormorant Garamond", Georgia', $css);
        self::assertStringContainsString('--radius-card: 18px;', $css);
        self::assertStringNotContainsString('motion', $css, 'Le niveau d\'animation passe par data-motion, pas par une variable.');
    }

    public function testFontsUrlOnlyContainsWhitelistedFamilies(): void
    {
        $url = (new ThemeTokens(self::DEFAULTS))->fontsUrl();

        self::assertStringStartsWith('https://fonts.googleapis.com/css2?', (string) $url);
        self::assertStringContainsString('family=Cormorant+Garamond:', (string) $url);
        self::assertStringContainsString('family=Jost:', (string) $url);
        self::assertNull(FontCatalog::googleUrl(['Inconnue']));
    }

    public function testContrastRatio(): void
    {
        self::assertEqualsWithDelta(21.0, ThemeTokens::contrastRatio('#000000', '#FFFFFF'), 0.01);
        // Texte principal sur fond crème du template par défaut : conforme WCAG AA (≥ 4,5).
        self::assertGreaterThan(4.5, ThemeTokens::contrastRatio('#5A4A44', '#F6EFDF'));
        // Texte foncé sur bouton doré.
        self::assertGreaterThan(4.5, ThemeTokens::contrastRatio('#17140F', '#DFB05E'));
    }

    public function testDefaultTemplateManifestIsValid(): void
    {
        $manifest = TemplateManifest::load(dirname(__DIR__, 2) . '/templates/afro-natural');

        self::assertSame('afro-natural', $manifest->slug);
        self::assertSame('split-logo', $manifest->variantFor('hero', null));
        self::assertSame('centered', $manifest->variantFor('hero', 'centered'));
        self::assertSame('split-logo', $manifest->variantFor('hero', 'inexistante'));
        self::assertSame('sticky', $manifest->layoutValue('header', null));

        // Tous les jetons par défaut doivent être valides, sinon ils seraient ignorés en silence.
        foreach ($manifest->tokens as $key => $value) {
            self::assertTrue(ThemeTokens::isValid($key, $value), "Jeton invalide dans le manifest : {$key} = {$value}");
        }

    }

    /** @return array<string, array{0: string}> */
    public static function templates(): array
    {
        $list = [];
        foreach (glob(dirname(__DIR__, 2) . '/templates/*/manifest.json') ?: [] as $file) {
            $slug = basename(dirname($file));
            $list[$slug] = [$slug];
        }

        return $list;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('templates')]
    public function testEveryTemplateIsComplete(string $slug): void
    {
        $root = dirname(__DIR__, 2);
        $manifest = TemplateManifest::load($root . '/templates/' . $slug);
        $reference = TemplateManifest::load($root . '/templates/afro-natural');

        // Mêmes jetons que le template de référence : le socle commun (site.css) les utilise tous.
        self::assertSame([], array_values(array_diff(array_keys($reference->tokens), array_keys($manifest->tokens))), 'Jetons manquants.');
        foreach ($manifest->tokens as $key => $value) {
            self::assertTrue(ThemeTokens::isValid($key, $value), "Jeton invalide : {$key} = {$value}");
        }

        // Lisibilité WCAG AA des couleurs par défaut (et texte clair sur bouton survolé).
        self::assertSame([], \App\Services\Theme\AppearanceService::contrastWarnings($manifest->tokens));
        self::assertGreaterThanOrEqual(4.5, ThemeTokens::contrastRatio($manifest->tokens['color.surface'], $manifest->tokens['color.accent-dark']));

        // Chaque variante déclarée a un gabarit : propre au template ou dans la bibliothèque partagée.
        foreach ($manifest->sections as $type => $variants) {
            foreach ($variants as $variant) {
                self::assertTrue(
                    is_file("{$manifest->path}/sections/{$type}/{$variant}.twig") || is_file("{$root}/resources/views/sections/{$type}/{$variant}.twig"),
                    "Gabarit introuvable : {$type}/{$variant}",
                );
            }
        }

        self::assertFileExists($manifest->path . '/layouts/page.twig');
        self::assertFileExists($manifest->path . '/assets/css/theme.css');
    }
}
