<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Repositories\PricingRepository;

/**
 * Données de catalogue minimales pour les tests (prestation à grille avec deux tailles).
 */
trait CatalogFixtures
{
    abstract protected function database(): Database;

    /**
     * @return array{category: int, service: int, variants: array<string, int>, lengths: array<string, int>}
     */
    protected function matrixService(string $slug = 'knotless-test', string $name = 'Knotless'): array
    {
        $db = $this->database();
        $pricing = new PricingRepository($db);

        $category = (int) $db->fetchValue(
            "INSERT INTO service_categories (slug, name) VALUES (:slug, 'Braids') RETURNING id",
            ['slug' => $slug . '-cat'],
        );
        $service = (int) $db->fetchValue(
            "INSERT INTO services (slug, category_id, name, pricing_mode, duration_minutes) VALUES (:slug, :c, :name, 'matrix', 240) RETURNING id",
            ['slug' => $slug, 'c' => $category, 'name' => $name],
        );

        $sizes = array_column($pricing->sizes(), 'id', 'code');
        $lengths = array_column($pricing->lengths(), 'id', 'slug');

        $s = $pricing->createVariant($service, $sizes['S'], null);
        $m = $pricing->createVariant($service, $sizes['M'], null);
        $pricing->updateVariant($m, ['label' => null, 'note' => null, 'image_media_id' => null, 'duration_minutes' => 300, 'is_active' => true]);

        $pricing->replaceCells([
            $s => [
                $lengths['epaules'] => ['kind' => 'fixed', 'price' => '90.00', 'duration' => 360],
                $lengths['classique'] => ['kind' => 'quote', 'price' => null, 'duration' => null],
            ],
            $m => [
                $lengths['epaules'] => ['kind' => 'fixed', 'price' => '70.00', 'duration' => null],
                $lengths['mi-dos'] => ['kind' => 'fixed', 'price' => '100.00', 'duration' => 380],
            ],
        ]);

        // Finitions et entretien proposés pour la catégorie.
        foreach (['finish', 'care'] as $group) {
            $db->execute(
                'INSERT INTO option_group_assignments (option_group_id, category_id) SELECT id, :c FROM option_groups WHERE code = :g',
                ['c' => $category, 'g' => $group],
            );
        }

        return ['category' => $category, 'service' => $service, 'variants' => ['S' => $s, 'M' => $m], 'lengths' => $lengths];
    }

    protected function optionId(string $name): int
    {
        return (int) $this->database()->fetchValue('SELECT id FROM service_options WHERE name = :n', ['n' => $name]);
    }
}
