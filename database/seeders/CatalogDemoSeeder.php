<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Seeding\Seeder;

/**
 * Catalogue de démonstration, structuré comme le site de référence :
 * rubriques à grille taille × longueur et rubriques à prix simple.
 *
 * LES PRIX SONT FICTIFS (calculés par formule) et doivent être remplacés
 * par les tarifs réels de Garance depuis l'administration.
 */
final class CatalogDemoSeeder extends Seeder
{
    /** Prix de base (longueur Nuque) et durée de base par taille, pour la formule fictive. */
    private const SIZE_BASE = [
        'S' => ['price' => 80, 'minutes' => 360],
        'M' => ['price' => 60, 'minutes' => 300],
        'L' => ['price' => 50, 'minutes' => 240],
        'XL' => ['price' => 45, 'minutes' => 180],
    ];

    /** Longueurs à tarif fixe (les deux dernières sont sur devis). */
    private const FIXED_LENGTHS = ['nuque', 'epaules', 'omoplates', 'soutien-gorge', 'mi-dos', 'taille', 'hanches', 'coccyx'];
    private const QUOTE_LENGTHS = ['classique', 'mi-cuisse'];

    private const CATEGORIES = [
        // slug => [nom, note d'en-tête, groupes d'options rattachés]
        'braids' => ['Braids & twists', null, ['finish', 'care', 'extra']],
        'fulani' => ['Fulani', null, ['finish', 'care', 'extra']],
        'nattes' => ['Nattes', 'Nattes collées réalisées sur vos cheveux naturels.', ['finish', 'care']],
        'locs-bantu' => ['Locs & bantu', null, ['finish', 'care']],
        'enfants' => ['Enfants (4 à 10 ans)', 'Pour les enfants âgés de 4 à 10 ans.', ['finish']],
        'tissages-varietes' => ['Tissages & variétés', null, ['care']],
    ];

    /**
     * Prestations à grille : slug => [catégorie, nom, tailles, première longueur proposée, alerte, notes par taille].
     */
    private const MATRIX_SERVICES = [
        'knotless' => ['braids', 'Knotless', ['S', 'M', 'L', 'XL'], 'nuque', null, []],
        'knotless-twist' => ['braids', 'Knotless twist', ['S', 'M', 'L'], 'epaules', null, ['S' => 'Avec mèches Xpression.', 'M' => 'Avec mèches Xpression.']],
        'bohemian' => ['braids', 'Bohemian', ['S', 'M', 'L', 'XL'], 'nuque', null, ['S' => 'En knotless braids.', 'M' => 'Avec knotless braids à l\'arrière.']],
        'bohemian-french-curl' => ['braids', 'Bohemian French curl', ['S', 'M', 'L', 'XL'], 'nuque', null, []],
        'box-twist' => ['braids', 'Box twist', ['S', 'M', 'L', 'XL'], 'epaules', null, []],
        'fulanis' => ['fulani', 'Fulanis', ['S', 'M'], 'nuque', 'Si knotless à l\'arrière, un supplément de 15 € s\'applique.', ['S' => 'Avec box braids à l\'arrière.', 'M' => 'Avec box braids à l\'arrière.']],
        'fulanis-nattes-collees' => ['fulani', 'Fulanis nattes collées', ['S', 'M', 'L', 'XL'], 'epaules', null, ['S' => 'Avec nattes collées à l\'arrière.']],
        'semi-fulani-braids' => ['fulani', 'Semi fulani braids', ['S', 'M'], 'epaules', 'Si knotless à l\'arrière, un supplément de 15 € s\'applique.', []],
    ];

    /**
     * Prestations à prix simple : slug => [catégorie, nom, price_kind, prix, durée en minutes].
     */
    private const SIMPLE_SERVICES = [
        'nattes-collees-xs' => ['nattes', 'Nattes collées taille XS', 'fixed', 55, 240],
        'nattes-collees-s' => ['nattes', 'Nattes collées taille S', 'fixed', 45, 180],
        'nattes-collees-m' => ['nattes', 'Nattes collées taille M', 'fixed', 35, 120],
        'nattes-a-l-ancienne' => ['nattes', 'Nattes à l\'ancienne', 'from', 50, 120],
        'faux-locs-crochet' => ['locs-bantu', 'Faux locs au crochet', 'fixed', 75, 300],
        'faux-locs-classiques' => ['locs-bantu', 'Faux locs classiques', 'from', 125, 420],
        'invisible-locs' => ['locs-bantu', 'Invisible locs', 'from', 65, 300],
        'bantu-knots' => ['locs-bantu', 'Bantu knots', 'fixed', 60, 120],
        'enfants-nattes-collees' => ['enfants', 'Nattes collées sans rajouts', 'fixed', 30, 90],
        'enfants-braids' => ['enfants', 'Braids taille M/L', 'fixed', 45, 180],
        'enfants-fulanis' => ['enfants', 'Fulanis', 'fixed', 45, 180],
        'tissage' => ['tissages-varietes', 'Tissage', 'from', 55, 150],
        'semi-tissage' => ['tissages-varietes', 'Semi tissage', 'from', 55, 150],
        'crochet-braids' => ['tissages-varietes', 'Crochet braids', 'from', 55, 150],
        'chignon' => ['tissages-varietes', 'Chignon', 'from', 50, 90],
    ];

    public function run(): void
    {
        $categoryIds = $this->seedCategories();
        $lengthIds = $this->idsBy('service_lengths', 'slug');
        $sizeIds = $this->idsBy('service_sizes', 'code');

        $position = 0;
        foreach (self::MATRIX_SERVICES as $slug => [$category, $name, $sizes, $firstLength, $alert, $notes]) {
            $serviceId = $this->upsertService($slug, $categoryIds[$category], $name, 'matrix', 'fixed', null, self::SIZE_BASE[$sizes[0]]['minutes'], $alert, $position++);
            $this->seedGrid($serviceId, $sizes, $firstLength, $notes, $sizeIds, $lengthIds);
        }

        foreach (self::SIMPLE_SERVICES as $slug => [$category, $name, $kind, $price, $minutes]) {
            $this->upsertService($slug, $categoryIds[$category], $name, 'simple', $kind, $price, $minutes, null, $position++);
        }

        $this->db->execute("UPDATE services SET is_featured = true WHERE slug IN ('knotless', 'bohemian', 'fulanis', 'box-twist')");
    }

    /** @return array<string, int> */
    private function seedCategories(): array
    {
        $ids = [];
        $position = 0;
        foreach (self::CATEGORIES as $slug => [$name, $note, $groups]) {
            $ids[$slug] = (int) $this->db->fetchValue(
                'INSERT INTO service_categories (slug, name, header_note, position) VALUES (:slug, :name, :note, :position)
                 ON CONFLICT (slug) DO UPDATE SET name = EXCLUDED.name, header_note = EXCLUDED.header_note
                 RETURNING id',
                ['slug' => $slug, 'name' => $name, 'note' => $note, 'position' => $position++],
            );

            foreach ($groups as $group) {
                $this->db->execute(
                    'INSERT INTO option_group_assignments (option_group_id, category_id)
                     SELECT id, :category FROM option_groups WHERE code = :group
                     ON CONFLICT ON CONSTRAINT option_group_assignments_unique DO NOTHING',
                    ['category' => $ids[$slug], 'group' => $group],
                );
            }
        }

        return $ids;
    }

    private function upsertService(
        string $slug,
        int $categoryId,
        string $name,
        string $mode,
        string $kind,
        ?int $price,
        int $minutes,
        ?string $alert,
        int $position,
    ): int {
        return (int) $this->db->fetchValue(
            'INSERT INTO services (slug, category_id, name, pricing_mode, price_kind, price, duration_minutes, alert_text, position)
             VALUES (:slug, :category, :name, :mode, :kind, :price, :minutes, :alert, :position)
             ON CONFLICT (slug) DO UPDATE SET
                 category_id = EXCLUDED.category_id, name = EXCLUDED.name, pricing_mode = EXCLUDED.pricing_mode,
                 price_kind = EXCLUDED.price_kind, price = EXCLUDED.price, duration_minutes = EXCLUDED.duration_minutes,
                 alert_text = EXCLUDED.alert_text
             RETURNING id',
            [
                'slug' => $slug,
                'category' => $categoryId,
                'name' => $name,
                'mode' => $mode,
                'kind' => $kind,
                'price' => $price === null ? null : (string) $price,
                'minutes' => $minutes,
                'alert' => $alert,
                'position' => $position,
            ],
        );
    }

    /**
     * Grille fictive : prix = base de la taille + 10 € par cran de longueur ; durée = base + 20 min par cran.
     *
     * @param list<string>          $sizes
     * @param array<string, string> $notes
     * @param array<string, int>    $sizeIds
     * @param array<string, int>    $lengthIds
     */
    private function seedGrid(int $serviceId, array $sizes, string $firstLength, array $notes, array $sizeIds, array $lengthIds): void
    {
        $firstIndex = (int) array_search($firstLength, self::FIXED_LENGTHS, true);

        foreach ($sizes as $position => $size) {
            $variantId = (int) $this->db->fetchValue(
                'INSERT INTO service_variants (service_id, size_id, note, duration_minutes, position)
                 VALUES (:service, :size, :note, :minutes, :position)
                 ON CONFLICT ON CONSTRAINT service_variants_unique DO UPDATE SET note = EXCLUDED.note
                 RETURNING id',
                [
                    'service' => $serviceId,
                    'size' => $sizeIds[$size],
                    'note' => $notes[$size] ?? null,
                    'minutes' => self::SIZE_BASE[$size]['minutes'],
                    'position' => $position,
                ],
            );

            foreach (self::FIXED_LENGTHS as $index => $length) {
                if ($index < $firstIndex) {
                    continue; // longueur non proposée
                }
                $this->db->execute(
                    "INSERT INTO variant_prices (variant_id, length_id, price_kind, price, duration_minutes)
                     VALUES (:variant, :length, 'fixed', :price, :minutes)
                     ON CONFLICT (variant_id, length_id) DO UPDATE SET price = EXCLUDED.price, duration_minutes = EXCLUDED.duration_minutes",
                    [
                        'variant' => $variantId,
                        'length' => $lengthIds[$length],
                        'price' => (string) (self::SIZE_BASE[$size]['price'] + 10 * $index),
                        'minutes' => self::SIZE_BASE[$size]['minutes'] + 20 * $index,
                    ],
                );
            }

            foreach (self::QUOTE_LENGTHS as $length) {
                $this->db->execute(
                    "INSERT INTO variant_prices (variant_id, length_id, price_kind, price)
                     VALUES (:variant, :length, 'quote', NULL)
                     ON CONFLICT (variant_id, length_id) DO NOTHING",
                    ['variant' => $variantId, 'length' => $lengthIds[$length]],
                );
            }
        }
    }

    /** @return array<string, int> */
    private function idsBy(string $table, string $column): array
    {
        $ids = [];
        foreach ($this->db->fetchAll("SELECT id, {$column} AS k FROM {$table}") as $row) {
            $ids[(string) $row['k']] = (int) $row['id'];
        }

        return $ids;
    }
}
