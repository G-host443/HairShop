<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class CategoryRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> avec nombre de prestations */
    public function all(): array
    {
        return $this->db->fetchAll(
            'SELECT c.*, (SELECT count(*) FROM services s WHERE s.category_id = c.id) AS services_count
             FROM service_categories c ORDER BY c.position, c.name'
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM service_categories WHERE id = :id', ['id' => $id]);
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT EXISTS (SELECT 1 FROM service_categories WHERE slug = :slug AND id IS DISTINCT FROM CAST(:id AS bigint))',
            ['slug' => $slug, 'id' => $exceptId],
        );
    }

    /** @param array{name: string, slug: string, description: ?string, header_note: ?string, image_media_id: ?int, is_active: bool} $data */
    public function create(array $data): int
    {
        return (int) $this->db->fetchValue(
            'INSERT INTO service_categories (name, slug, description, header_note, image_media_id, is_active, position)
             VALUES (:name, :slug, :description, :note, :image, :active, (SELECT COALESCE(max(position), -1) + 1 FROM service_categories))
             RETURNING id',
            ['name' => $data['name'], 'slug' => $data['slug'], 'description' => $data['description'], 'note' => $data['header_note'], 'image' => $data['image_media_id'], 'active' => $data['is_active']],
        );
    }

    /** @param array{name: string, slug: string, description: ?string, header_note: ?string, image_media_id: ?int, is_active: bool} $data */
    public function update(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE service_categories SET name = :name, slug = :slug, description = :description, header_note = :note,
                    image_media_id = :image, is_active = :active WHERE id = :id',
            ['name' => $data['name'], 'slug' => $data['slug'], 'description' => $data['description'], 'note' => $data['header_note'], 'image' => $data['image_media_id'], 'active' => $data['is_active'], 'id' => $id],
        );
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM service_categories WHERE id = :id', ['id' => $id]);
    }

    public function servicesCount(int $id): int
    {
        return (int) $this->db->fetchValue('SELECT count(*) FROM services WHERE category_id = :id', ['id' => $id]);
    }

    /** @param list<int> $ids ordre souhaité */
    public function reorder(array $ids): void
    {
        $this->db->transaction(function () use ($ids): void {
            foreach (array_values($ids) as $position => $id) {
                $this->db->execute('UPDATE service_categories SET position = :p WHERE id = :id', ['p' => $position, 'id' => (int) $id]);
            }
        });
    }

    /** @return list<int> groupes d'options rattachés à la catégorie */
    public function optionGroupIds(int $categoryId): array
    {
        return array_map('intval', array_column($this->db->fetchAll(
            'SELECT option_group_id FROM option_group_assignments WHERE category_id = :id AND NOT is_excluded',
            ['id' => $categoryId],
        ), 'option_group_id'));
    }

    /** @param list<int> $groupIds */
    public function setOptionGroups(int $categoryId, array $groupIds): void
    {
        $this->db->transaction(function () use ($categoryId, $groupIds): void {
            $this->db->execute('DELETE FROM option_group_assignments WHERE category_id = :id', ['id' => $categoryId]);
            foreach (array_unique($groupIds) as $groupId) {
                $this->db->execute(
                    'INSERT INTO option_group_assignments (option_group_id, category_id) VALUES (:g, :c)',
                    ['g' => $groupId, 'c' => $categoryId],
                );
            }
        });
    }
}
