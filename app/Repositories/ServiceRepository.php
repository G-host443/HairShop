<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class ServiceRepository
{
    private const FIELDS = [
        'category_id', 'name', 'slug', 'summary', 'description', 'pricing_mode', 'price_kind', 'price',
        'duration_minutes', 'alert_text', 'note', 'benefits', 'steps', 'recommendations',
        'is_featured', 'is_active', 'seo_title', 'seo_description',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array{category?: ?int, q?: ?string, status?: ?string} $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $where = ['TRUE'];
        $params = [];
        if (!empty($filters['category'])) {
            $where[] = 's.category_id = :category';
            $params['category'] = (int) $filters['category'];
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = 's.name ILIKE :q';
            $params['q'] = '%' . addcslashes((string) $filters['q'], '%_\\') . '%';
        }
        if (($filters['status'] ?? '') === 'active') {
            $where[] = 's.is_active';
        } elseif (($filters['status'] ?? '') === 'inactive') {
            $where[] = 'NOT s.is_active';
        }

        return $this->db->fetchAll(
            'SELECT s.*, c.name AS category_name,
                    (SELECT count(*) FROM service_variants v WHERE v.service_id = s.id) AS variants_count,
                    (SELECT min(vp.price) FROM service_variants v JOIN variant_prices vp ON vp.variant_id = v.id
                      WHERE v.service_id = s.id AND vp.price_kind = \'fixed\') AS min_price,
                    (SELECT m.id FROM service_images si JOIN media m ON m.id = si.media_id
                      WHERE si.service_id = s.id ORDER BY si.is_cover DESC, si.position LIMIT 1) AS cover_media_id
             FROM services s JOIN service_categories c ON c.id = s.category_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY c.position, c.name, s.position, s.name',
            $params,
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM services WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->decode($row);
    }

    /** @return array<string, mixed>|null */
    public function findActiveBySlug(string $slug): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT s.*, c.name AS category_name, c.slug AS category_slug, c.is_active AS category_active
             FROM services s JOIN service_categories c ON c.id = s.category_id
             WHERE s.slug = :slug AND s.is_active AND c.is_active',
            ['slug' => $slug],
        );

        return $row === null ? null : $this->decode($row);
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT EXISTS (SELECT 1 FROM services WHERE slug = :slug AND id IS DISTINCT FROM CAST(:id AS bigint))',
            ['slug' => $slug, 'id' => $exceptId],
        );
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $params = $this->params($data);
        $columns = implode(', ', array_keys($params));
        $placeholders = implode(', ', array_map(static fn ($k) => in_array($k, ['benefits', 'steps'], true) ? "CAST(:{$k} AS jsonb)" : ':' . $k, array_keys($params)));

        return (int) $this->db->fetchValue(
            "INSERT INTO services ({$columns}, position) VALUES ({$placeholders},
                (SELECT COALESCE(max(position), -1) + 1 FROM services WHERE category_id = :category_id_pos)) RETURNING id",
            $params + ['category_id_pos' => $params['category_id']],
        );
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $params = $this->params($data);
        $sets = implode(', ', array_map(static fn ($k) => in_array($k, ['benefits', 'steps'], true) ? "{$k} = CAST(:{$k} AS jsonb)" : "{$k} = :{$k}", array_keys($params)));

        $this->db->execute("UPDATE services SET {$sets} WHERE id = :id", $params + ['id' => $id]);
    }

    public function hasBookings(int $id): bool
    {
        return (bool) $this->db->fetchValue('SELECT EXISTS (SELECT 1 FROM bookings WHERE service_id = :id)', ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM services WHERE id = :id', ['id' => $id]);
    }

    /** @param list<int> $ids */
    public function reorder(array $ids): void
    {
        $this->db->transaction(function () use ($ids): void {
            foreach (array_values($ids) as $position => $id) {
                $this->db->execute('UPDATE services SET position = :p WHERE id = :id', ['p' => $position, 'id' => (int) $id]);
            }
        });
    }

    // --- Photos ---

    /** @return list<int> identifiants des médias, couverture en premier */
    public function imageIds(int $serviceId): array
    {
        return array_map('intval', array_column($this->db->fetchAll(
            'SELECT media_id FROM service_images WHERE service_id = :id ORDER BY is_cover DESC, position',
            ['id' => $serviceId],
        ), 'media_id'));
    }

    /** @param list<int> $mediaIds la première est la couverture */
    public function setImages(int $serviceId, array $mediaIds): void
    {
        $this->db->transaction(function () use ($serviceId, $mediaIds): void {
            $this->db->execute('DELETE FROM service_images WHERE service_id = :id', ['id' => $serviceId]);
            foreach (array_values(array_unique($mediaIds)) as $position => $mediaId) {
                $this->db->execute(
                    'INSERT INTO service_images (service_id, media_id, position, is_cover) VALUES (:s, :m, :p, :cover)',
                    ['s' => $serviceId, 'm' => $mediaId, 'p' => $position, 'cover' => $position === 0],
                );
            }
        });
    }

    // --- Prestations complémentaires ---

    /** @return list<int> */
    public function relatedIds(int $serviceId): array
    {
        return array_map('intval', array_column($this->db->fetchAll(
            'SELECT related_service_id FROM service_related WHERE service_id = :id ORDER BY position',
            ['id' => $serviceId],
        ), 'related_service_id'));
    }

    /** @param list<int> $ids */
    public function setRelated(int $serviceId, array $ids): void
    {
        $this->db->transaction(function () use ($serviceId, $ids): void {
            $this->db->execute('DELETE FROM service_related WHERE service_id = :id', ['id' => $serviceId]);
            foreach (array_values(array_unique(array_diff($ids, [$serviceId]))) as $position => $relatedId) {
                $this->db->execute(
                    'INSERT INTO service_related (service_id, related_service_id, position) VALUES (:s, :r, :p)',
                    ['s' => $serviceId, 'r' => $relatedId, 'p' => $position],
                );
            }
        });
    }

    /** @return list<array<string, mixed>> prestations complémentaires actives (site public) */
    public function activeRelated(int $serviceId): array
    {
        return $this->db->fetchAll(
            'SELECT s.id, s.slug, s.name, s.summary FROM service_related r
             JOIN services s ON s.id = r.related_service_id
             JOIN service_categories c ON c.id = s.category_id
             WHERE r.service_id = :id AND s.is_active AND c.is_active ORDER BY r.position',
            ['id' => $serviceId],
        );
    }

    // --- Groupes d'options propres à la prestation (ajouts / exclusions) ---

    /** @return array<int, bool> groupe => true (ajouté) / false (exclu) */
    public function optionGroupOverrides(int $serviceId): array
    {
        $overrides = [];
        foreach ($this->db->fetchAll('SELECT option_group_id, is_excluded FROM option_group_assignments WHERE service_id = :id', ['id' => $serviceId]) as $row) {
            $overrides[(int) $row['option_group_id']] = !$row['is_excluded'];
        }

        return $overrides;
    }

    /** @param array<int, bool> $overrides groupe => true (ajouter) / false (exclure) */
    public function setOptionGroupOverrides(int $serviceId, array $overrides): void
    {
        $this->db->transaction(function () use ($serviceId, $overrides): void {
            $this->db->execute('DELETE FROM option_group_assignments WHERE service_id = :id', ['id' => $serviceId]);
            foreach ($overrides as $groupId => $included) {
                $this->db->execute(
                    'INSERT INTO option_group_assignments (option_group_id, service_id, is_excluded) VALUES (:g, :s, :excluded)',
                    ['g' => $groupId, 's' => $serviceId, 'excluded' => !$included],
                );
            }
        });
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function params(array $data): array
    {
        $params = [];
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $params[$field] = in_array($field, ['benefits', 'steps'], true)
                    ? json_encode(array_values((array) $data[$field]), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                    : $data[$field];
            }
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decode(array $row): array
    {
        foreach (['benefits', 'steps'] as $field) {
            $row[$field] = json_decode((string) $row[$field], true) ?: [];
        }

        return $row;
    }
}
