<?php

declare(strict_types=1);

namespace App\Sections;

/**
 * Lecture typée et défensive du contenu JSON d'une section : une donnée mal formée
 * ne doit jamais casser l'affichage de la page.
 */
final class Content
{
    /** @param array<string, mixed> $content */
    public static function text(array $content, string $key, string $default = ''): string
    {
        $value = $content[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /** @param array<string, mixed> $content */
    public static function bool(array $content, string $key, bool $default = false): bool
    {
        return isset($content[$key]) ? (bool) $content[$key] : $default;
    }

    /** @param array<string, mixed> $content */
    public static function int(array $content, string $key, int $default, int $min = 1, int $max = 100): int
    {
        $value = filter_var($content[$key] ?? $default, FILTER_VALIDATE_INT);

        return max($min, min($max, $value === false ? $default : $value));
    }

    /**
     * Bouton { label, target } ; la cible doit être une ancre (#tarifs), un chemin du site (/contact)
     * ou une URL http(s). Toute autre valeur (javascript:…) est refusée.
     *
     * @param array<string, mixed> $content
     * @return array{label: string, target: string}|null
     */
    public static function link(array $content, string $key): ?array
    {
        $link = $content[$key] ?? null;
        if (!is_array($link)) {
            return null;
        }
        $label = self::text($link, 'label');
        $target = self::text($link, 'target');

        return $label !== '' && self::isSafeTarget($target) ? ['label' => $label, 'target' => $target] : null;
    }

    /** Cible de bouton acceptée : ancre (#tarifs), chemin du site (/contact) ou URL http(s). */
    public static function isSafeTarget(string $target): bool
    {
        return preg_match('~^(#[a-z0-9-]+|/[^/\s][^\s]*|/|https?://[^\s]+)$~i', $target) === 1;
    }

    /** Identifiant d'ancre sûr pour un attribut id. */
    public static function anchor(array $content): ?string
    {
        $anchor = self::text($content, 'anchor');

        return preg_match('/^[a-z][a-z0-9-]{0,40}$/', $anchor) === 1 ? $anchor : null;
    }
}
