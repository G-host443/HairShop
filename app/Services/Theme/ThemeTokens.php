<?php

declare(strict_types=1);

namespace App\Services\Theme;

/**
 * Jetons de design d'un template (couleurs, polices, rayons, espacements, animations)
 * et leur traduction en variables CSS.
 *
 * Valeurs finales = valeurs par défaut du manifest, remplacées par les réglages enregistrés
 * (template_settings). Toute valeur invalide est ignorée : le site ne peut pas être cassé
 * par un réglage erroné.
 */
final class ThemeTokens
{
    public const MOTION_LEVELS = ['none', 'subtle', 'lively'];

    /** @var array<string, string> */
    private array $tokens;

    /**
     * @param array<string, string> $defaults   jetons du manifest
     * @param array<string, mixed>  $overrides  réglages personnalisés (clé « tokens »)
     */
    public function __construct(array $defaults, array $overrides = [])
    {
        $tokens = [];
        foreach ($defaults as $key => $value) {
            if (self::isValid($key, (string) $value)) {
                $tokens[$key] = (string) $value;
            }
        }
        foreach ($overrides as $key => $value) {
            // Seules les clés déclarées par le template peuvent être personnalisées.
            if (isset($tokens[$key]) && is_string($value) && self::isValid($key, $value)) {
                $tokens[$key] = $value;
            }
        }

        $this->tokens = $tokens;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->tokens[$key] ?? $default;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->tokens;
    }

    /**
     * Règle de validation selon le préfixe de la clé.
     */
    public static function isValid(string $key, string $value): bool
    {
        $type = explode('.', $key, 2)[0];

        return match ($type) {
            'color' => (bool) preg_match('/^#[0-9A-Fa-f]{6}$/', $value),
            'font' => FontCatalog::exists($value),
            'radius', 'space', 'size' => (bool) preg_match('/^\d{1,4}(\.\d{1,2})?(px|rem|em)$/', $value),
            'motion' => in_array($value, self::MOTION_LEVELS, true),
            default => false,
        } && preg_match('/^[a-z]+\.[a-z0-9-]+$/', $key) === 1;
    }

    /**
     * Bloc :root avec une variable par jeton : « color.accent-dark » → --color-accent-dark.
     */
    public function toCss(): string
    {
        $lines = [];
        foreach ($this->tokens as $key => $value) {
            [$type, $name] = explode('.', $key, 2);
            if ($type === 'motion') {
                continue; // géré par l'attribut data-motion sur <html>
            }
            $css = $type === 'font' ? FontCatalog::stack($value) : $value;
            $lines[] = '  --' . $type . '-' . $name . ': ' . $css . ';';

            // Variante RVB des couleurs, utile pour les transparences : rgb(var(--color-accent-rgb) / .2)
            if ($type === 'color') {
                [$r, $g, $b] = sscanf($value, '#%02x%02x%02x');
                $lines[] = '  --' . $type . '-' . $name . '-rgb: ' . $r . ' ' . $g . ' ' . $b . ';';
            }
        }

        return ":root {\n" . implode("\n", $lines) . "\n}\n";
    }

    /** URL Google Fonts des polices utilisées par le thème. */
    public function fontsUrl(): ?string
    {
        $fonts = [];
        foreach ($this->tokens as $key => $value) {
            if (str_starts_with($key, 'font.')) {
                $fonts[] = $value;
            }
        }

        return FontCatalog::googleUrl($fonts);
    }

    public function motion(): string
    {
        return $this->tokens['motion.level'] ?? 'subtle';
    }

    /**
     * Rapport de contraste WCAG entre deux couleurs #RRGGBB (de 1 à 21).
     */
    public static function contrastRatio(string $foreground, string $background): float
    {
        $luminance = static function (string $hex): float {
            $channels = array_map(static function (int $c): float {
                $c /= 255;

                return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            }, sscanf($hex, '#%02x%02x%02x'));

            return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
        };

        $l1 = $luminance($foreground);
        $l2 = $luminance($background);

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }
}
