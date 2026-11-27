<?php

declare(strict_types=1);

namespace App\Helpers;

use Transliterator;

final class Slug
{
    /**
     * « Nattes à l'ancienne » → « nattes-a-l-ancienne ».
     */
    public static function make(string $text, int $maxLength = 80): string
    {
        static $transliterator = null;
        $transliterator ??= Transliterator::create('Any-Latin; Latin-ASCII; Lower()');

        $ascii = $transliterator !== null ? (string) $transliterator->transliterate($text) : strtolower($text);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $ascii), '-');
        $slug = rtrim(substr($slug, 0, $maxLength), '-');

        return $slug !== '' ? $slug : 'element';
    }

    public static function isValid(string $slug): bool
    {
        return preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) === 1 && strlen($slug) <= 120;
    }

    /**
     * Prix saisi par l'utilisateur : « 45 », « 45,50 », « 45.5 € » → « 45.50 » ; null si invalide.
     */
    public static function parsePrice(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $clean = str_replace([' ', "\u{00A0}", '€'], '', trim((string) $value));
        $clean = str_replace(',', '.', $clean);

        if (!preg_match('/^\d{1,6}(\.\d{1,2})?$/', $clean)) {
            return null;
        }

        return number_format((float) $clean, 2, '.', '');
    }
}
