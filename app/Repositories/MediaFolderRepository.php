<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class MediaFolderRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Arborescence aplatie, triée, avec profondeur et nombre de médias.
     *
     * @return list<array{id: int, parent_id: ?int, name: string, depth: int, media_count: int}>
     */
    public function tree(): array
    {
        $rows = $this->db->fetchAll(
            'WITH RECURSIVE t AS (
                SELECT id, parent_id, name, 0 AS depth, ARRAY[lower(name)] AS sort_path
                FROM media_folders WHERE parent_id IS NULL
                UNION ALL
                SELECT f.id, f.parent_id, f.name, t.depth + 1, t.sort_path || lower(f.name)
                FROM media_folders f JOIN t ON f.parent_id = t.id
            )
            SELECT t.id, t.parent_id, t.name, t.depth,
                   (SELECT count(*) FROM media m WHERE m.folder_id = t.id) AS media_count
            FROM t ORDER BY t.sort_path'
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'parent_id' => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
            'name' => (string) $r['name'],
            'depth' => (int) $r['depth'],
            'media_count' => (int) $r['media_count'],
        ], $rows);
    }

    /** @return array{id: int, parent_id: ?int, name: string}|null */
    public function find(int $id): ?array
    {
        $row = $this->db->fetchOne('SELECT id, parent_id, name FROM media_folders WHERE id = :id', ['id' => $id]);

        return $row === null ? null : [
            'id' => (int) $row['id'],
            'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'name' => (string) $row['name'],
        ];
    }

    public function exists(int $id): bool
    {
        return (bool) $this->db->fetchValue('SELECT EXISTS (SELECT 1 FROM media_folders WHERE id = :id)', ['id' => $id]);
    }

    public function nameTaken(string $name, ?int $parentId, ?int $exceptId = null): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT EXISTS (SELECT 1 FROM media_folders
             WHERE lower(name) = lower(:name) AND parent_id IS NOT DISTINCT FROM CAST(:parent AS bigint) AND id IS DISTINCT FROM CAST(:except AS bigint))',
            ['name' => $name, 'parent' => $parentId, 'except' => $exceptId],
        );
    }

    /** Vrai si $candidateId est $folderId lui-même ou l'un de ses descendants (évite les cycles). */
    public function isSelfOrDescendant(int $candidateId, int $folderId): bool
    {
        return (bool) $this->db->fetchValue(
            'WITH RECURSIVE d AS (
                SELECT id FROM media_folders WHERE id = :folder
                UNION ALL
                SELECT f.id FROM media_folders f JOIN d ON f.parent_id = d.id
            ) SELECT EXISTS (SELECT 1 FROM d WHERE id = :candidate)',
            ['folder' => $folderId, 'candidate' => $candidateId],
        );
    }

    public function create(string $name, ?int $parentId): int
    {
        return (int) $this->db->fetchValue(
            'INSERT INTO media_folders (name, parent_id) VALUES (:name, :parent) RETURNING id',
            ['name' => $name, 'parent' => $parentId],
        );
    }

    public function update(int $id, string $name, ?int $parentId): void
    {
        $this->db->execute('UPDATE media_folders SET name = :name, parent_id = :parent WHERE id = :id', [
            'name' => $name, 'parent' => $parentId, 'id' => $id,
        ]);
    }

    /**
     * Supprime le dossier et ses sous-dossiers ; les médias qu'ils contenaient passent « sans dossier ».
     */
    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM media_folders WHERE id = :id', ['id' => $id]);
    }
}
