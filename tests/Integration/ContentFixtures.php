<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;

/**
 * Données de test pour la galerie, les avis et les promotions.
 */
trait ContentFixtures
{
    abstract protected function database(): Database;

    /** Prestation à prix unique dans sa propre catégorie. */
    protected function simpleService(string $slug = 'nattes-test', string $name = 'Nattes collées', string $price = '50.00'): int
    {
        $db = $this->database();
        $category = (int) $db->fetchValue("INSERT INTO service_categories (slug, name) VALUES (:slug, 'Nattes') RETURNING id", ['slug' => $slug . '-cat']);

        return (int) $db->fetchValue(
            "INSERT INTO services (slug, category_id, name, pricing_mode, price_kind, price, duration_minutes)
             VALUES (:slug, :c, :name, 'simple', 'fixed', :price, 120) RETURNING id",
            ['slug' => $slug, 'c' => $category, 'name' => $name, 'price' => $price],
        );
    }

    /** Image de la bibliothèque (ligne seule : aucun fichier n'est nécessaire pour générer les URL). */
    protected function image(string $name = 'photo', string $alt = ''): int
    {
        return (int) $this->database()->fetchValue(
            "INSERT INTO media (disk_path, original_name, mime_type, size_bytes, width, height, alt_text, variants)
             VALUES (:path, :name, 'image/jpeg', 1000, 800, 1000, :alt, CAST(:variants AS jsonb)) RETURNING id",
            [
                'path' => "test/{$name}-" . bin2hex(random_bytes(4)) . '.jpg',
                'name' => $name . '.jpg',
                'alt' => $alt,
                'variants' => json_encode(['thumb' => "test/{$name}-thumb.webp", 'medium' => "test/{$name}-medium.webp", 'large' => "test/{$name}-large.webp"]),
            ],
        );
    }

    /** @param list<int> $serviceIds */
    protected function promotion(array $serviceIds, string $type, string $value, string $starts, string $ends, bool $active = true, string $name = 'Offre test'): int
    {
        $db = $this->database();
        $id = (int) $db->fetchValue(
            'INSERT INTO promotions (name, discount_type, value, starts_at, ends_at, is_active)
             VALUES (:n, :t, :v, CAST(:s AS timestamptz), CAST(:e AS timestamptz), :a) RETURNING id',
            ['n' => $name, 't' => $type, 'v' => $value, 's' => $starts, 'e' => $ends, 'a' => $active],
        );
        foreach ($serviceIds as $serviceId) {
            $db->execute('INSERT INTO promotion_services (promotion_id, service_id) VALUES (:p, :s)', ['p' => $id, 's' => $serviceId]);
        }

        return $id;
    }
}
