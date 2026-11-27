<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Tailles, longueurs, variantes et cellules de la grille de prix.
 */
final class PricingRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    // --- Tailles et longueurs ---

    /** @return list<array{id: int, code: string, label: string, position: int, used: int}> */
    public function sizes(): array
    {
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'code' => (string) $r['code'], 'label' => (string) $r['label'],
            'position' => (int) $r['position'], 'used' => (int) $r['used'],
        ], $this->db->fetchAll(
            'SELECT z.*, (SELECT count(*) FROM service_variants v WHERE v.size_id = z.id) AS used FROM service_sizes z ORDER BY z.position, z.id'
        ));
    }

    /** @return list<array{id: int, slug: string, label: string, position: int, used: int}> */
    public function lengths(): array
    {
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'slug' => (string) $r['slug'], 'label' => (string) $r['label'],
            'position' => (int) $r['position'], 'used' => (int) $r['used'],
        ], $this->db->fetchAll(
            'SELECT l.*, (SELECT count(*) FROM variant_prices vp WHERE vp.length_id = l.id) AS used FROM service_lengths l ORDER BY l.position, l.id'
        ));
    }

    public function createSize(string $code, string $label): int
    {
        return (int) $this->db->fetchValue(
            'INSERT INTO service_sizes (code, label, position) VALUES (:code, :label, (SELECT COALESCE(max(position), -1) + 1 FROM service_sizes)) RETURNING id',
            ['code' => $code, 'label' => $label],
        );
    }

    public function updateSize(int $id, string $code, string $label): void
    {
        $this->db->execute('UPDATE service_sizes SET code = :code, label = :label WHERE id = :id', ['code' => $code, 'label' => $label, 'id' => $id]);
    }

    public function sizeCodeExists(string $code, ?int $exceptId = null): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT EXISTS (SELECT 1 FROM service_sizes WHERE upper(code) = upper(:code) AND id IS DISTINCT FROM CAST(:id AS bigint))',
            ['code' => $code, 'id' => $exceptId],
        );
    }

    public function deleteSize(int $id): bool
    {
        if ((int) $this->db->fetchValue('SELECT count(*) FROM service_variants WHERE size_id = :id', ['id' => $id]) > 0) {
            return false;
        }

        return $this->db->execute('DELETE FROM service_sizes WHERE id = :id', ['id' => $id]) === 1;
    }

    public function createLength(string $slug, string $label): int
    {
        return (int) $this->db->fetchValue(
            'INSERT INTO service_lengths (slug, label, position) VALUES (:slug, :label, (SELECT COALESCE(max(position), -1) + 1 FROM service_lengths)) RETURNING id',
            ['slug' => $slug, 'label' => $label],
        );
    }

    public function updateLength(int $id, string $label): void
    {
        $this->db->execute('UPDATE service_lengths SET label = :label WHERE id = :id', ['label' => $label, 'id' => $id]);
    }

    public function lengthSlugExists(string $slug): bool
    {
        return (bool) $this->db->fetchValue('SELECT EXISTS (SELECT 1 FROM service_lengths WHERE slug = :slug)', ['slug' => $slug]);
    }

    public function deleteLength(int $id): bool
    {
        if ((int) $this->db->fetchValue('SELECT count(*) FROM variant_prices WHERE length_id = :id', ['id' => $id]) > 0) {
            return false;
        }

        return $this->db->execute('DELETE FROM service_lengths WHERE id = :id', ['id' => $id]) === 1;
    }

    /** @param list<int> $ids */
    public function reorder(string $table, array $ids): void
    {
        if (!in_array($table, ['service_sizes', 'service_lengths'], true)) {
            throw new \InvalidArgumentException('Table non triable.');
        }
        $this->db->transaction(function () use ($table, $ids): void {
            foreach (array_values($ids) as $position => $id) {
                $this->db->execute("UPDATE {$table} SET position = :p WHERE id = :id", ['p' => $position, 'id' => (int) $id]);
            }
        });
    }

    // --- Variantes ---

    /** @return list<array<string, mixed>> variantes d'une prestation avec leur taille, triées */
    public function variants(int $serviceId): array
    {
        return $this->db->fetchAll(
            'SELECT v.*, z.code AS size_code, z.label AS size_label
             FROM service_variants v JOIN service_sizes z ON z.id = v.size_id
             WHERE v.service_id = :id ORDER BY z.position, v.position, v.id',
            ['id' => $serviceId],
        );
    }

    /** @return array<string, mixed>|null */
    public function findVariant(int $variantId): ?array
    {
        return $this->db->fetchOne(
            'SELECT v.*, z.code AS size_code FROM service_variants v JOIN service_sizes z ON z.id = v.size_id WHERE v.id = :id',
            ['id' => $variantId],
        );
    }

    public function variantExists(int $serviceId, int $sizeId, ?string $label): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT EXISTS (SELECT 1 FROM service_variants WHERE service_id = :s AND size_id = :z AND label IS NOT DISTINCT FROM CAST(:l AS text))',
            ['s' => $serviceId, 'z' => $sizeId, 'l' => $label],
        );
    }

    public function createVariant(int $serviceId, int $sizeId, ?string $label): int
    {
        return (int) $this->db->fetchValue(
            'INSERT INTO service_variants (service_id, size_id, label, position)
             VALUES (:s, :z, :l, (SELECT COALESCE(max(position), -1) + 1 FROM service_variants WHERE service_id = :s2)) RETURNING id',
            ['s' => $serviceId, 'z' => $sizeId, 'l' => $label, 's2' => $serviceId],
        );
    }

    /** @param array{label: ?string, note: ?string, image_media_id: ?int, duration_minutes: ?int, is_active: bool} $data */
    public function updateVariant(int $variantId, array $data): void
    {
        $this->db->execute(
            'UPDATE service_variants SET label = :label, note = :note, image_media_id = :image, duration_minutes = :duration, is_active = :active WHERE id = :id',
            ['label' => $data['label'], 'note' => $data['note'], 'image' => $data['image_media_id'], 'duration' => $data['duration_minutes'], 'active' => $data['is_active'], 'id' => $variantId],
        );
    }

    public function deleteVariant(int $variantId): void
    {
        $this->db->execute('DELETE FROM service_variants WHERE id = :id', ['id' => $variantId]);
    }

    // --- Cellules ---

    /**
     * @return array<int, array<int, array{kind: string, price: ?string, duration: ?int}>> variante => longueur => cellule
     */
    public function grid(int $serviceId): array
    {
        $grid = [];
        foreach ($this->db->fetchAll(
            'SELECT vp.* FROM variant_prices vp JOIN service_variants v ON v.id = vp.variant_id WHERE v.service_id = :id',
            ['id' => $serviceId],
        ) as $row) {
            $grid[(int) $row['variant_id']][(int) $row['length_id']] = [
                'kind' => (string) $row['price_kind'],
                'price' => $row['price'] !== null ? (string) $row['price'] : null,
                'duration' => $row['duration_minutes'] !== null ? (int) $row['duration_minutes'] : null,
            ];
        }

        return $grid;
    }

    /**
     * Remplace toutes les cellules des variantes données.
     *
     * @param array<int, array<int, array{kind: string, price: ?string, duration: ?int}>> $cells variante => longueur => cellule
     */
    public function replaceCells(array $cells): void
    {
        $this->db->transaction(function () use ($cells): void {
            foreach ($cells as $variantId => $byLength) {
                $this->db->execute('DELETE FROM variant_prices WHERE variant_id = :v', ['v' => $variantId]);
                foreach ($byLength as $lengthId => $cell) {
                    $this->db->execute(
                        'INSERT INTO variant_prices (variant_id, length_id, price_kind, price, duration_minutes) VALUES (:v, :l, :k, :p, :d)',
                        ['v' => $variantId, 'l' => $lengthId, 'k' => $cell['kind'], 'p' => $cell['price'], 'd' => $cell['duration']],
                    );
                }
            }
        });
    }

    /** @return array{kind: string, price: ?string, duration: ?int}|null */
    public function cell(int $variantId, int $lengthId): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM variant_prices WHERE variant_id = :v AND length_id = :l', ['v' => $variantId, 'l' => $lengthId]);

        return $row === null ? null : [
            'kind' => (string) $row['price_kind'],
            'price' => $row['price'] !== null ? (string) $row['price'] : null,
            'duration' => $row['duration_minutes'] !== null ? (int) $row['duration_minutes'] : null,
        ];
    }
}
