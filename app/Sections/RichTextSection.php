<?php

declare(strict_types=1);

namespace App\Sections;

use App\Helpers\SimpleMarkup;

/**
 * Bloc de texte mis en forme (intertitres, listes, gras, italique, liens) avec une syntaxe simple :
 * aucun HTML n'est saisi, le rendu est produit et échappé par SimpleMarkup.
 */
final class RichTextSection implements SectionType
{
    public function type(): string
    {
        return 'rich_text';
    }

    public function label(): string
    {
        return 'Texte';
    }

    public function fields(): array
    {
        return [
            'anchor' => ['label' => 'Ancre (lien du menu)', 'type' => 'anchor'],
            'title' => ['label' => 'Titre', 'type' => 'text', 'max' => 120],
            'text' => ['label' => 'Texte', 'type' => 'richtext', 'max' => 20000],
        ];
    }

    public function prepare(array $content): ?array
    {
        $text = Content::text($content, 'text');
        if ($text === '') {
            return null;
        }

        return [
            'title' => Content::text($content, 'title'),
            'html' => SimpleMarkup::toHtml($text),
        ];
    }
}
