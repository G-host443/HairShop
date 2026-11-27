<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class GalleryRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> toutes les réalisations (administration), dans l'ordre d'affichage */
    public function all(?int $categoryId = null): array
    {
        return array_map(self::hydrate(...), $this->db->fetchAll(
            'SELECT g.*, c.name AS category_name, s.name AS service_name
             FROM gallery_items g
             LEFT JOIN service_categories c ON c.id = g.category_id
             LEFT JOIN services s ON s.id = g.service_id
             WHERE CAST(:cat AS bigint) IS NULL OR g.category_id = CAST(:cat2 AS bigint)
             ORDER BY g.position, g.id DESC',
            ['cat' => $categoryId, 'cat2' => $categoryId],
        ));
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM gallery_items WHERE id = :id', ['id' => $id]);

        return $row !== null ? self::hydrate($row) : null;
    }

    /**
     * Réalisations publiées, filtrées (site public).
     *
     * @param array{category?: ?int, tag?: ?string, q?: ?string, service?: ?int} $filters
     * @return list<array<string, mixed>>
     */
    public function published(array $filters = [], int $limit = 60, int $offset = 0): array
    {
        [$where, $params] = self::filters($filters);

        return array_map(self::hydrate(...), $this->db->fetchAll(
            "SELECT g.*, c.name AS category_name, c.slug AS category_slug, s.name AS service_name, s.slug AS service_slug
             FROM gallery_items g
             LEFT JOIN service_categories c ON c.id = g.category_id
             LEFT JOIN services s ON s.id = g.service_id AND s.is_active
             WHERE {$where}
             ORDER BY g.is_featured DESC, g.position, g.id DESC
             LIMIT :limit OFFSET :offset",
            $params + ['limit' => $limit, 'offset' => $offset],
        ));
    }

    /** @param array{category?: ?int, tag?: ?string, q?: ?string, service?: ?int} $filters */
    public function countPublished(array $filters = []): int
    {
        [$where, $params] = self::filters($filters);

        return (int) $this->db->fetchValue("SELECT count(*) FROM gallery_items g WHERE {$where}", $params);
    }

    /** @return list<array{id: int, slug: string, name: string, count: int}> catégories ayant au moins une réalisation publiée */
    public function publishedCategories(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'slug' => (string) $r['slug'], 'name' => (string) $r['name'], 'count' => (int) $r['count']],
            $this->db->fetchAll(
                'SELECT c.id, c.slug, c.name, count(*) AS count
                 FROM gallery_items g JOIN service_categories c ON c.id = g.category_id
                 WHERE g.is_published AND c.is_active
                 GROUP BY c.id, c.slug, c.name, c.position ORDER BY c.position, c.name'
            ));
    }

    /** @return list<string> tags utilisés par les réalisations publiées, du plus fréquent au moins fréquent */
    public function publishedTags(int $limit = 20): array
    {
        return array_column($this->db->fetchAll(
            'SELECT t.tag, count(*) AS n FROM gallery_items g, unnest(g.tags) AS t(tag)
             WHERE g.is_published GROUP BY t.tag ORDER BY n DESC, t.tag LIMIT :limit',
            ['limit' => $limit],
        ), 'tag');
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $data['position'] = (int) $this->db->fetchValue('SELECT COALESCE(min(position), 0) - 1 FROM gallery_items');

        return (int) $this->db->fetchValue(
            'INSERT INTO gallery_items (media_id, after_media_id, title, description, category_id, service_id, tags, is_featured, is_published, position)
             VALUES (:media, :after, :title, :description, :category, :service, CAST(:tags AS text[]), :featured, :published, :position) RETURNING id',
            self::params($data) + ['position' => $data['position']],
        );
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE gallery_items SET media_id = :media, after_media_id = :after, title = :title, description = :description,
                category_id = :category, service_id = :service, tags = CAST(:tags AS text[]), is_featured = :featured, is_published = :published
             WHERE id = :id',
            self::params($data) + ['id' => $id],
        );
    }

    public function setFlag(int $id, string $flag, bool $value): void
    {
        $column = match ($flag) {
            'featured' => 'is_featured',
            'published' => 'is_published',
        };
        $this->db->execute("UPDATE gallery_items SET {$column} = :v WHERE id = :id", ['id' => $id, 'v' => $value]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM gallery_items WHERE id = :id', ['id' => $id]);
    }

    /** @param list<int> $ids */
    public function reorder(array $ids): void
    {
        $this->db->transaction(function () use ($ids): void {
            foreach (array_values($ids) as $position => $id) {
                $this->db->execute('UPDATE gallery_items SET position = :p WHERE id = :id', ['p' => $position, 'id' => $id]);
            }
        });
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private static function filters(array $filters): array
    {
        $where = ['g.is_published'];
        $params = [];
        if (!empty($filters['category'])) {
            $where[] = 'g.category_id = :category';
            $params['category'] = (int) $filters['category'];
        }
        if (!empty($filters['service'])) {
            $where[] = 'g.service_id = :service';
            $params['service'] = (int) $filters['service'];
        }
        if (($filters['tag'] ?? '') !== '') {
            $where[] = ':tag = ANY (g.tags)';
            $params['tag'] = (string) $filters['tag'];
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = "(g.title ILIKE :q OR g.description ILIKE :q2 OR array_to_string(g.tags, ' ') ILIKE :q3)";
            $like = '%' . addcslashes((string) $filters['q'], '%_\\') . '%';
            $params += ['q' => $like, 'q2' => $like, 'q3' => $like];
        }

        return [implode(' AND ', $where), $params];
    }

    private static function params(array $data): array
    {
        return [
            'media' => $data['media_id'],
            'after' => $data['after_media_id'],
            'title' => $data['title'],
            'description' => $data['description'],
            'category' => $data['category_id'],
            'service' => $data['service_id'],
            'tags' => self::pgArray($data['tags']),
            'featured' => $data['is_featured'],
            'published' => $data['is_published'],
        ];
    }

    /** @param list<string> $values littéral de tableau PostgreSQL, chaque valeur entre guillemets échappés */
    private static function pgArray(array $values): string
    {
        return '{' . implode(',', array_map(static fn (string $v): string => '"' . addcslashes($v, '"\\') . '"', $values)) . '}';
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['media_id'] = (int) $row['media_id'];
        $row['after_media_id'] = $row['after_media_id'] !== null ? (int) $row['after_media_id'] : null;
        $raw = trim((string) $row['tags'], '{}');
        $row['tags'] = $raw === '' ? [] : array_map(static fn (string $t): string => stripcslashes(trim($t, '"')), str_getcsv($raw, ',', '"', '\\'));

        return $row;
    }
}
