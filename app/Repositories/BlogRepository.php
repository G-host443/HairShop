<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Articles, catégories et tags du blog.
 *
 * Visibilité publique : article publié, ou programmé dont la date est passée (même si la tâche
 * planifiée n'est pas encore passée pour changer son statut) — jamais un brouillon.
 */
final class BlogRepository
{
    private const VISIBLE = "(p.status = 'published' OR p.status = 'scheduled') AND p.published_at <= CAST(:now AS timestamptz)";

    public function __construct(private readonly Database $db)
    {
    }

    // ---------- Articles : administration ----------

    /**
     * @param array{status?: ?string, category?: ?int, q?: ?string} $filters
     * @return list<array<string, mixed>>
     */
    public function adminList(array $filters = []): array
    {
        $where = ['TRUE'];
        $params = [];
        if (in_array($filters['status'] ?? null, ['draft', 'scheduled', 'published'], true)) {
            $where[] = 'p.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['category'])) {
            $where[] = 'p.category_id = :category';
            $params['category'] = (int) $filters['category'];
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = 'p.title ILIKE :q';
            $params['q'] = '%' . addcslashes((string) $filters['q'], '%_\\') . '%';
        }

        return $this->db->fetchAll(
            'SELECT p.id, p.title, p.slug, p.status, p.published_at, p.updated_at, p.cover_media_id, c.name AS category_name,
                    trim(u.first_name || \' \' || u.last_name) AS author_name
             FROM blog_posts p
             LEFT JOIN blog_categories c ON c.id = p.category_id
             LEFT JOIN users u ON u.id = p.author_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY (p.status = \'draft\') DESC, COALESCE(p.published_at, p.updated_at) DESC, p.id DESC',
            $params,
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT p.*, c.name AS category_name, c.slug AS category_slug FROM blog_posts p LEFT JOIN blog_categories c ON c.id = p.category_id WHERE p.id = :id',
            ['id' => $id],
        );
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT EXISTS (SELECT 1 FROM blog_posts WHERE slug = :slug AND id <> COALESCE(CAST(:id AS bigint), 0))',
            ['slug' => $slug, 'id' => $exceptId],
        );
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->fetchValue(
            'INSERT INTO blog_posts (category_id, author_id, title, slug, excerpt, content, cover_media_id, status, published_at, seo_title, seo_description)
             VALUES (:category, :author, :title, :slug, :excerpt, :content, :cover, :status, CAST(:published AS timestamptz), :seo_title, :seo_description)
             RETURNING id',
            self::params($data) + ['author' => $data['author_id']],
        );
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE blog_posts SET category_id = :category, title = :title, slug = :slug, excerpt = :excerpt, content = :content,
                cover_media_id = :cover, status = :status, published_at = CAST(:published AS timestamptz),
                seo_title = :seo_title, seo_description = :seo_description
             WHERE id = :id',
            self::params($data) + ['id' => $id],
        );
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM blog_posts WHERE id = :id', ['id' => $id]);
    }

    /** Passe en « publié » les articles programmés dont la date est arrivée. @return list<array{id: int, title: string}> */
    public function publishDue(string $now): array
    {
        return $this->db->fetchAll(
            "UPDATE blog_posts SET status = 'published' WHERE status = 'scheduled' AND published_at <= CAST(:now AS timestamptz) RETURNING id, title",
            ['now' => $now],
        );
    }

    /** @return array<string, int> */
    public function statusCounts(): array
    {
        $counts = ['draft' => 0, 'scheduled' => 0, 'published' => 0];
        foreach ($this->db->fetchAll('SELECT status, count(*) AS n FROM blog_posts GROUP BY status') as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    // ---------- Articles : site public ----------

    /**
     * @param array{category?: ?int, tag?: ?int} $filters
     * @return list<array<string, mixed>>
     */
    public function visible(string $now, array $filters = [], int $limit = 12, int $offset = 0, ?int $exceptId = null): array
    {
        [$where, $params] = self::visibleFilters($now, $filters, $exceptId);

        return $this->db->fetchAll(
            "SELECT p.id, p.title, p.slug, p.excerpt, p.content, p.cover_media_id, p.published_at, c.name AS category_name, c.slug AS category_slug
             FROM blog_posts p LEFT JOIN blog_categories c ON c.id = p.category_id
             WHERE {$where}
             ORDER BY p.published_at DESC, p.id DESC
             LIMIT :limit OFFSET :offset",
            $params + ['limit' => $limit, 'offset' => $offset],
        );
    }

    /** @param array{category?: ?int, tag?: ?int} $filters */
    public function countVisible(string $now, array $filters = []): int
    {
        [$where, $params] = self::visibleFilters($now, $filters, null);

        return (int) $this->db->fetchValue("SELECT count(*) FROM blog_posts p WHERE {$where}", $params);
    }

    /** @return array<string, mixed>|null */
    public function findVisibleBySlug(string $slug, string $now): ?array
    {
        return $this->db->fetchOne(
            'SELECT p.*, c.name AS category_name, c.slug AS category_slug, trim(u.first_name) AS author_first_name
             FROM blog_posts p
             LEFT JOIN blog_categories c ON c.id = p.category_id
             LEFT JOIN users u ON u.id = p.author_id
             WHERE p.slug = :slug AND ' . self::VISIBLE,
            ['slug' => $slug, 'now' => $now],
        );
    }

    // ---------- Catégories ----------

    /** @return list<array<string, mixed>> avec le nombre d'articles */
    public function categories(): array
    {
        return $this->db->fetchAll(
            'SELECT c.*, (SELECT count(*) FROM blog_posts p WHERE p.category_id = c.id) AS posts_count
             FROM blog_categories c ORDER BY c.position, c.name'
        );
    }

    /** @return list<array{id: int, name: string, slug: string, count: int}> catégories ayant des articles visibles */
    public function visibleCategories(string $now): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'slug' => (string) $r['slug'], 'count' => (int) $r['n']],
            $this->db->fetchAll(
                'SELECT c.id, c.name, c.slug, count(*) AS n FROM blog_categories c JOIN blog_posts p ON p.category_id = c.id
                 WHERE ' . self::VISIBLE . ' GROUP BY c.id, c.name, c.slug, c.position ORDER BY c.position, c.name',
                ['now' => $now],
            ));
    }

    /** @return array<string, mixed>|null */
    public function findCategory(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM blog_categories WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function findCategoryBySlug(string $slug): ?array
    {
        return $this->db->fetchOne('SELECT * FROM blog_categories WHERE slug = :slug', ['slug' => $slug]);
    }

    public function categorySlugExists(string $slug, ?int $exceptId = null): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT EXISTS (SELECT 1 FROM blog_categories WHERE slug = :slug AND id <> COALESCE(CAST(:id AS bigint), 0))',
            ['slug' => $slug, 'id' => $exceptId],
        );
    }

    public function createCategory(string $name, string $slug): int
    {
        return (int) $this->db->fetchValue(
            'INSERT INTO blog_categories (name, slug, position) VALUES (:n, :s, (SELECT COALESCE(max(position), -1) + 1 FROM blog_categories)) RETURNING id',
            ['n' => $name, 's' => $slug],
        );
    }

    public function updateCategory(int $id, string $name, string $slug): void
    {
        $this->db->execute('UPDATE blog_categories SET name = :n, slug = :s WHERE id = :id', ['id' => $id, 'n' => $name, 's' => $slug]);
    }

    public function deleteCategory(int $id): void
    {
        $this->db->execute('DELETE FROM blog_categories WHERE id = :id', ['id' => $id]);
    }

    /** @param list<int> $ids */
    public function reorderCategories(array $ids): void
    {
        $this->db->transaction(function () use ($ids): void {
            foreach (array_values($ids) as $position => $id) {
                $this->db->execute('UPDATE blog_categories SET position = :p WHERE id = :id', ['p' => $position, 'id' => $id]);
            }
        });
    }

    // ---------- Tags ----------

    /** @return list<array<string, mixed>> avec le nombre d'articles */
    public function tags(): array
    {
        return $this->db->fetchAll(
            'SELECT t.*, (SELECT count(*) FROM blog_post_tags pt WHERE pt.tag_id = t.id) AS posts_count FROM blog_tags t ORDER BY t.name'
        );
    }

    /** @return array<string, mixed>|null */
    public function findTag(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM blog_tags WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function findTagBySlug(string $slug): ?array
    {
        return $this->db->fetchOne('SELECT * FROM blog_tags WHERE slug = :slug', ['slug' => $slug]);
    }

    /** @return list<array{id: int, name: string, slug: string}> */
    public function postTags(int $postId): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'slug' => (string) $r['slug']],
            $this->db->fetchAll('SELECT t.id, t.name, t.slug FROM blog_tags t JOIN blog_post_tags pt ON pt.tag_id = t.id WHERE pt.post_id = :p ORDER BY t.name', ['p' => $postId]));
    }

    /** Tag existant (même adresse) ou nouveau tag. */
    public function tagId(string $name, string $slug): int
    {
        $id = $this->db->fetchValue('SELECT id FROM blog_tags WHERE slug = :s', ['s' => $slug]);

        return $id !== null ? (int) $id : (int) $this->db->fetchValue('INSERT INTO blog_tags (name, slug) VALUES (:n, :s) RETURNING id', ['n' => $name, 's' => $slug]);
    }

    /** @param list<int> $tagIds */
    public function setPostTags(int $postId, array $tagIds): void
    {
        $this->db->execute('DELETE FROM blog_post_tags WHERE post_id = :p', ['p' => $postId]);
        foreach (array_unique($tagIds) as $tagId) {
            $this->db->execute('INSERT INTO blog_post_tags (post_id, tag_id) VALUES (:p, :t)', ['p' => $postId, 't' => $tagId]);
        }
    }

    public function renameTag(int $id, string $name): void
    {
        $this->db->execute('UPDATE blog_tags SET name = :n WHERE id = :id', ['id' => $id, 'n' => $name]);
    }

    public function deleteTag(int $id): void
    {
        $this->db->execute('DELETE FROM blog_tags WHERE id = :id', ['id' => $id]);
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private static function visibleFilters(string $now, array $filters, ?int $exceptId): array
    {
        $where = [self::VISIBLE];
        $params = ['now' => $now];
        if (!empty($filters['category'])) {
            $where[] = 'p.category_id = :category';
            $params['category'] = (int) $filters['category'];
        }
        if (!empty($filters['tag'])) {
            $where[] = 'EXISTS (SELECT 1 FROM blog_post_tags pt WHERE pt.post_id = p.id AND pt.tag_id = :tag)';
            $params['tag'] = (int) $filters['tag'];
        }
        if ($exceptId !== null) {
            $where[] = 'p.id <> :except';
            $params['except'] = $exceptId;
        }

        return [implode(' AND ', $where), $params];
    }

    private static function params(array $data): array
    {
        return [
            'category' => $data['category_id'],
            'title' => $data['title'],
            'slug' => $data['slug'],
            'excerpt' => $data['excerpt'],
            'content' => $data['content'],
            'cover' => $data['cover_media_id'],
            'status' => $data['status'],
            'published' => $data['published_at'],
            'seo_title' => $data['seo_title'],
            'seo_description' => $data['seo_description'],
        ];
    }
}
