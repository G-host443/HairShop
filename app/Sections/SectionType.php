<?php

declare(strict_types=1);

namespace App\Sections;

/**
 * Type de section de page (hero, tarifs, galerie…).
 *
 * Le contenu (JSON en base) est commun à tous les templates ; chaque template ne fournit
 * que le gabarit Twig. fields() décrit le formulaire d'édition du constructeur de pages (SectionForm) :
 * types text, textarea, richtext, bool, int, link, media, anchor ; options max, min, default, help.
 */
interface SectionType
{
    public function type(): string;

    public function label(): string;

    /**
     * @return array<string, array{label: string, type: string, help?: string, max?: int, min?: int, default?: mixed}>
     */
    public function fields(): array;

    /**
     * Données passées au gabarit, ou null pour ne pas afficher la section
     * (ex. galerie sans aucune photo publiée).
     *
     * @param array<string, mixed> $content
     * @return array<string, mixed>|null
     */
    public function prepare(array $content): ?array;
}
