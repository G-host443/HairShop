<?php

declare(strict_types=1);

namespace App\Helpers;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

/**
 * Nettoyage du HTML des articles (éditeur riche) par liste blanche.
 *
 * Le HTML reçu est analysé avec le parseur HTML5 de PHP, puis un NOUVEAU code HTML est écrit à partir
 * de l'arbre : seules les balises et les attributs autorisés sont recopiés, tout le texte est échappé.
 * Rien de ce qui n'est pas explicitement prévu ne peut traverser (script, style, événements, iframe…).
 *
 * - Liens : http(s), mailto:, tel:, chemins du site et ancres ; les liens externes s'ouvrent dans un nouvel onglet.
 * - Images : uniquement celles de la bibliothèque du site (/uploads/…).
 * - Balises inconnues : supprimées, leur texte est conservé (sauf script, style… supprimés avec leur contenu).
 */
final class HtmlSanitizer
{
    /** Balises autorisées et attributs permis pour chacune. */
    private const ALLOWED = [
        'p' => [], 'br' => [], 'h2' => [], 'h3' => [],
        'strong' => [], 'em' => [], 'u' => [], 's' => [],
        'ul' => [], 'ol' => [], 'li' => [],
        'blockquote' => [],
        'a' => ['href'],
        'img' => ['src', 'alt', 'data-media-id'],
    ];

    /** Équivalences : b → strong, i → em, h1 → h2, h4-h6 → h3… */
    private const RENAME = ['b' => 'strong', 'i' => 'em', 'strike' => 's', 'del' => 's', 'h1' => 'h2', 'h4' => 'h3', 'h5' => 'h3', 'h6' => 'h3', 'div' => 'p'];

    /** Supprimées avec leur contenu. */
    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'template', 'noscript', 'svg', 'math', 'form', 'input', 'button', 'select', 'textarea', 'video', 'audio', 'head', 'title', 'meta', 'link'];

    private const VOID = ['br', 'img'];

    public static function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = HTMLDocument::createFromString('<!doctype html><html><body>' . $html . '</body></html>', LIBXML_NOERROR);
        if ($document->body === null) {
            return '';
        }
        $out = self::children($document->body);

        // Paragraphes vides laissés par l'éditeur (<p><br></p>) en fin et début de texte.
        $out = preg_replace('#^(<p>(<br>)?</p>)+|(<p>(<br>)?</p>)+$#', '', $out) ?? $out;

        return trim($out);
    }

    /** Texte brut (extraits, temps de lecture). */
    public static function toText(string $html): string
    {
        $text = html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '</li>', '</h2>', '</h3>'], ["\n", "\n", "\n", "\n", "\n"], $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/[ \t]+/", ' ', preg_replace("/\n{2,}/", "\n", $text) ?? $text) ?? $text);
    }

    private static function children(Node $parent): string
    {
        $out = '';
        foreach ($parent->childNodes as $child) {
            $out .= self::node($child);
        }

        return $out;
    }

    private static function node(Node $node): string
    {
        if ($node instanceof Text) {
            return self::escape($node->data);
        }
        if (!$node instanceof Element) {
            return ''; // commentaires, instructions…
        }

        $tag = strtolower($node->localName);
        if (in_array($tag, self::DROP, true)) {
            return '';
        }
        $tag = self::RENAME[$tag] ?? $tag;

        // Quill 2 écrit les listes à puces <ol><li data-list="bullet"> : on rétablit <ul>.
        if ($tag === 'ol') {
            foreach ($node->childNodes as $item) {
                if ($item instanceof Element && strtolower($item->localName) === 'li') {
                    $tag = $item->getAttribute('data-list') === 'bullet' ? 'ul' : 'ol';
                    break;
                }
            }
        }

        if (!isset(self::ALLOWED[$tag])) {
            return self::children($node); // balise inconnue : on garde le texte
        }

        $attributes = self::attributes($tag, $node);
        if ($attributes === null) {
            return $tag === 'a' ? self::children($node) : ''; // lien dangereux : texte seul ; image refusée : supprimée
        }

        $open = '<' . $tag . $attributes . '>';
        if (in_array($tag, self::VOID, true)) {
            return $open;
        }

        return $open . self::children($node) . '</' . $tag . '>';
    }

    /** @return string|null attributs sérialisés, ou null si l'élément doit être écarté */
    private static function attributes(string $tag, Element $node): ?string
    {
        $out = '';

        if ($tag === 'a') {
            $href = trim((string) $node->getAttribute('href'));
            if (!self::isSafeLink($href)) {
                return null;
            }
            $out .= ' href="' . self::escape($href) . '"';
            if (preg_match('~^https?://~i', $href)) {
                $out .= ' target="_blank" rel="noopener noreferrer"';
            }
        }

        if ($tag === 'img') {
            $src = trim((string) $node->getAttribute('src'));
            if (preg_match('~^/uploads/[A-Za-z0-9/_.-]+\.(jpe?g|png|webp|gif|avif)$~i', $src) !== 1 || str_contains($src, '..')) {
                return null;
            }
            $out .= ' src="' . self::escape($src) . '" alt="' . self::escape(mb_substr(trim((string) $node->getAttribute('alt')), 0, 200)) . '" loading="lazy"';
            $mediaId = (string) $node->getAttribute('data-media-id');
            if (preg_match('/^\d{1,12}$/', $mediaId) === 1) {
                $out .= ' data-media-id="' . $mediaId . '"';
            }
        }

        return $out;
    }

    public static function isSafeLink(string $href): bool
    {
        if ($href === '' || preg_match('/[\x00-\x20\x7F]/', $href) === 1) {
            return false;
        }

        return preg_match('~^(https?://[^\s"\'<>]+|mailto:[^\s"\'<>@]+@[^\s"\'<>]+|tel:\+?[0-9 .()-]{4,25}|/(?!/)[^\s"\'<>]*|#[A-Za-z0-9_-]+)$~i', $href) === 1;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
