<?php

declare(strict_types=1);

namespace App\Services\Theme;

/**
 * Liste blanche des polices Google Fonts proposées dans l'administration.
 * Aucune URL de police n'est saisie librement : la CSP n'autorise que fonts.googleapis.com.
 */
final class FontCatalog
{
    /** nom => [catégorie (pile de secours), axes Google Fonts] */
    public const FONTS = [
        // Titres
        'Cormorant Garamond' => ['serif', 'ital,wght@0,500;0,600;1,500'],
        'Playfair Display' => ['serif', 'ital,wght@0,500;0,700;1,500'],
        'Lora' => ['serif', 'ital,wght@0,500;0,600;1,500'],
        'DM Serif Display' => ['serif', 'ital@0;1'],
        'Bodoni Moda' => ['serif', 'ital,wght@0,500;0,700;1,500'],
        // Textes
        'Jost' => ['sans', 'wght@400;500;600'],
        'Montserrat' => ['sans', 'wght@400;500;600'],
        'Poppins' => ['sans', 'wght@400;500;600'],
        'Inter' => ['sans', 'wght@400;500;600'],
        'Nunito Sans' => ['sans', 'wght@400;600'],
        'Outfit' => ['sans', 'wght@400;500;600'],
        // Manuscrites
        'Parisienne' => ['script', ''],
        'Great Vibes' => ['script', ''],
        'Dancing Script' => ['script', 'wght@500;600'],
        'Allura' => ['script', ''],
    ];

    private const FALLBACKS = [
        'serif' => 'Georgia, "Times New Roman", serif',
        'sans' => 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
        'script' => 'cursive',
    ];

    public static function exists(string $font): bool
    {
        return isset(self::FONTS[$font]);
    }

    /** Valeur CSS font-family complète, avec polices de secours. */
    public static function stack(string $font): string
    {
        $category = self::FONTS[$font][0] ?? 'sans';

        return '"' . $font . '", ' . self::FALLBACKS[$category];
    }

    /**
     * URL Google Fonts chargeant toutes les polices demandées en une seule requête.
     *
     * @param list<string> $fonts
     */
    public static function googleUrl(array $fonts): ?string
    {
        $families = [];
        foreach (array_unique($fonts) as $font) {
            if (!self::exists($font)) {
                continue;
            }
            $axes = self::FONTS[$font][1];
            $families[] = 'family=' . str_replace(' ', '+', $font) . ($axes !== '' ? ':' . $axes : '');
        }

        return $families === [] ? null : 'https://fonts.googleapis.com/css2?' . implode('&', $families) . '&display=swap';
    }

    /** @return array<string, list<string>> polices groupées par catégorie (pour les listes déroulantes) */
    public static function grouped(): array
    {
        $grouped = [];
        foreach (self::FONTS as $name => [$category]) {
            $grouped[$category][] = $name;
        }

        return $grouped;
    }
}
