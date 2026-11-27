<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Mise en forme simple des blocs de texte, sans HTML saisi :
 *
 *   ## Titre               → intertitre
 *   - élément              → liste à puces
 *   **gras**, *italique*
 *   [texte du lien](/contact) ou (https://…), (mailto:…), (tel:…)
 *   ligne vide             → nouveau paragraphe ; retour à la ligne simple conservé
 *
 * Le texte est échappé AVANT toute transformation : aucune balise ni attribut ne peut être injecté,
 * et les liens n'acceptent que des adresses sûres (pas de javascript:).
 */
final class SimpleMarkup
{
    public static function toHtml(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ($text === '') {
            return '';
        }

        $html = [];
        foreach (preg_split('/\n{2,}/', $text) ?: [] as $block) {
            $lines = array_values(array_filter(array_map('rtrim', explode("\n", trim($block))), static fn (string $l): bool => $l !== ''));

            // Intertitre en tête de bloc ; la suite du bloc est traitée normalement (liste ou paragraphe).
            if ($lines !== [] && preg_match('/^#{2,3}\s+(.+)$/', $lines[0], $m)) {
                $html[] = '<h3>' . self::inline($m[1]) . '</h3>';
                array_shift($lines);
            }
            if ($lines !== []) {
                $html[] = self::block($lines);
            }
        }

        return implode("\n", $html);
    }

    /** @param non-empty-list<string> $lines */
    private static function block(array $lines): string
    {
        // Bloc ne contenant que des éléments de liste.
        if (array_filter($lines, static fn (string $l): bool => !preg_match('/^\s*[-*]\s+/', $l)) === []) {
            $items = array_map(static fn (string $l): string => '<li>' . self::inline(preg_replace('/^\s*[-*]\s+/', '', $l) ?? '') . '</li>', $lines);

            return '<ul>' . implode('', $items) . '</ul>';
        }

        return '<p>' . implode('<br>', array_map(self::inline(...), $lines)) . '</p>';
    }

    /** Version texte brut (aperçus, extraits) : la syntaxe est retirée. */
    public static function toPlainText(string $text): string
    {
        $text = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $text) ?? $text;
        $text = preg_replace('/^\s*(#{2,3}|[-*])\s+/m', '', $text) ?? $text;

        return trim(str_replace(['**', '*'], '', $text));
    }

    public static function isSafeUrl(string $url): bool
    {
        return preg_match('~^(/(?!/)[^\s]*|#[a-z0-9-]+|https?://[^\s]+|mailto:[^\s@]+@[^\s]+|tel:\+?[0-9 .-]{4,20})$~i', $url) === 1;
    }

    private static function inline(string $line): string
    {
        $escaped = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        // Liens : le texte et l'adresse sont déjà échappés ; l'adresse est revérifiée sous sa forme d'origine.
        $escaped = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $m): string {
            $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!self::isSafeUrl($url)) {
                return $m[1];
            }
            $external = preg_match('~^https?://~i', $url) === 1;

            return '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"'
                . ($external ? ' target="_blank" rel="noopener"' : '') . '>' . $m[1] . '</a>';
        }, $escaped) ?? $escaped;

        $escaped = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $escaped) ?? $escaped;

        return preg_replace('/(?<![*\w])\*(?=\S)(.+?)(?<=\S)\*(?![*\w])/u', '<em>$1</em>', $escaped) ?? $escaped;
    }
}
