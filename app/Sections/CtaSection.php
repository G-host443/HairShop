<?php

declare(strict_types=1);

namespace App\Sections;

final class CtaSection implements SectionType
{
    public function type(): string
    {
        return 'cta';
    }

    public function label(): string
    {
        return 'Appel à l\'action';
    }

    public function fields(): array
    {
        return [
            'title' => ['label' => 'Titre', 'type' => 'text', 'max' => 120],
            'text' => ['label' => 'Texte', 'type' => 'textarea', 'max' => 400],
            'button' => ['label' => 'Bouton', 'type' => 'link'],
        ];
    }

    public function prepare(array $content): ?array
    {
        $title = Content::text($content, 'title');
        $button = Content::link($content, 'button');

        return $title === '' || $button === null ? null : [
            'title' => $title,
            'text' => Content::text($content, 'text'),
            'button' => $button,
        ];
    }
}
