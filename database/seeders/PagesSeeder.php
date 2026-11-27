<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Seeding\Seeder;

/**
 * Pages de base du site.
 *
 * - Accueil (publiée) avec la composition de sections du template afro-natural.
 * - Contact (publiée).
 * - FAQ et pages légales créées en brouillon et sans contenu : leur texte dépend de l'entreprise
 *   (SIRET, hébergeur, conditions…) et doit être rédigé dans l'administration avant publication.
 *
 * Une page existante n'est jamais modifiée.
 */
final class PagesSeeder extends Seeder
{
    private const PAGES = [
        ['accueil', 'Accueil', 'home', 'published'],
        ['contact', 'Contact', 'system', 'published'],
        ['faq', 'Questions fréquentes', 'standard', 'draft'],
        ['mentions-legales', 'Mentions légales', 'legal', 'draft'],
        ['conditions-generales', 'Conditions générales', 'legal', 'draft'],
        ['politique-de-confidentialite', 'Politique de confidentialité', 'legal', 'draft'],
        ['politique-d-annulation', 'Politique d\'annulation', 'legal', 'draft'],
    ];

    public function run(): void
    {
        foreach (self::PAGES as [$slug, $title, $kind, $status]) {
            $pageId = $this->db->fetchValue(
                "INSERT INTO pages (slug, title, kind, status, published_at)
                 VALUES (:slug, :title, :kind, :status, CASE WHEN :status2 = 'published' THEN now() END)
                 ON CONFLICT (slug) DO NOTHING
                 RETURNING id",
                ['slug' => $slug, 'title' => $title, 'kind' => $kind, 'status' => $status, 'status2' => $status],
            );

            // null = la page existait déjà : on ne touche pas à ses sections.
            if ($pageId === null) {
                continue;
            }

            foreach ($this->sectionsFor($slug) as $position => [$type, $variant, $content]) {
                $this->db->execute(
                    'INSERT INTO page_sections (page_id, type, variant, variant_template, position, content)
                     VALUES (:page, :type, :variant, CASE WHEN CAST(:variant2 AS text) IS NULL THEN NULL ELSE \'afro-natural\' END, :position, CAST(:content AS jsonb))',
                    [
                        'page' => (int) $pageId,
                        'type' => $type,
                        'variant' => $variant,
                        'variant2' => $variant,
                        'position' => $position,
                        'content' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ],
                );
            }
        }
    }

    /**
     * @return list<array{0: string, 1: ?string, 2: array<string, mixed>}>
     */
    private function sectionsFor(string $slug): array
    {
        return match ($slug) {
            'accueil' => [
                ['hero', 'split-logo', [
                    'eyebrow' => 'Hair_style by Garance',
                    'title' => 'Des tresses et coiffures protectrices, réalisées avec soin',
                    'text' => 'Knotless, box braids, bohemian, fulani, twists, faux locs, nattes, coiffures enfants… Choisissez votre coiffure, sa taille et sa longueur : le prix s\'affiche aussitôt et vous réservez votre créneau en quelques clics.',
                    'location_from_settings' => true,
                    'primary_cta' => ['label' => 'Choisir ma coiffure et réserver', 'target' => '#tarifs'],
                    'secondary_cta' => ['label' => 'Voir mes réalisations', 'target' => '#galerie'],
                ]],
                ['pricing_grid', 'chips', [
                    'anchor' => 'tarifs',
                    'title' => 'Tarifs',
                    'intro' => 'Le prix dépend de la taille des tresses (S = fines, XL = épaisses) et de la longueur. Touchez une longueur pour la sélectionner, puis réservez.',
                ]],
                ['gallery', 'grid', [
                    'anchor' => 'galerie',
                    'title' => 'Réalisations',
                    'intro' => 'Quelques coiffures réalisées récemment.',
                    'limit' => 8,
                    'featured_only' => true,
                ]],
                ['practical_info', 'three-columns', [
                    'anchor' => 'infos',
                    'title' => 'Infos pratiques',
                    'show_location' => true,
                    'show_hours' => true,
                    'show_rules' => true,
                ]],
                ['testimonials', 'cards', [
                    'title' => 'Elles m\'ont fait confiance',
                    'limit' => 6,
                ]],
                ['contact', 'buttons', [
                    'anchor' => 'contact',
                    'title' => 'Prendre rendez-vous',
                    'text_from_settings' => true,
                ]],
            ],
            'contact' => [
                ['contact', 'form', [
                    'title' => 'Me contacter',
                    'text_from_settings' => true,
                    'show_form' => true,
                    'show_hours' => true,
                ]],
            ],
            default => [],
        };
    }
}
