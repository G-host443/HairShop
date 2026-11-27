<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Config;
use App\Core\Database;
use App\Models\Media;

final class MediaRepository
{
    private string $baseUrl;

    public function __construct(private readonly Database $db, Config $config)
    {
        $this->baseUrl = (string) $config->get('media.upload_url', '/uploads');
    }

    public function find(int $id): ?Media
    {
        $row = $this->db->fetchOne('SELECT * FROM media WHERE id = :id', ['id' => $id]);

        return $row === null ? null : Media::fromRow($row, $this->baseUrl);
    }

    /**
     * @param list<int> $ids
     * @return array<int, Media> indexés par id
     */
    public function findMany(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        $rows = $this->db->fetchAll(
            'SELECT * FROM media WHERE id = ANY (CAST(:ids AS bigint[]))',
            ['ids' => '{' . implode(',', $ids) . '}'],
        );

        $media = [];
        foreach ($rows as $row) {
            $media[(int) $row['id']] = Media::fromRow($row, $this->baseUrl);
        }

        return $media;
    }

    /**
     * Recherche paginée.
     *
     * @param array{folder?: int|string|null, type?: string|null, q?: string|null} $filters
     *        folder : id, « none » (sans dossier) ou null (tous)
     * @return array{items: list<Media>, total: int}
     */
    public function search(array $filters, int $page, int $perPage): array
    {
        $where = ['TRUE'];
        $params = [];

        $folder = $filters['folder'] ?? null;
        if ($folder === 'none') {
            $where[] = 'folder_id IS NULL';
        } elseif ($folder !== null && $folder !== '') {
            $where[] = 'folder_id = :folder';
            $params['folder'] = (int) $folder;
        }

        $type = $filters['type'] ?? null;
        if ($type === 'image' || $type === 'video') {
            $where[] = 'mime_type LIKE :type';
            $params['type'] = $type . '/%';
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(original_name ILIKE :q OR alt_text ILIKE :q2)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $params['q'] = $like;
            $params['q2'] = $like;
        }

        $sqlWhere = implode(' AND ', $where);
        $total = (int) $this->db->fetchValue("SELECT count(*) FROM media WHERE {$sqlWhere}", $params);

        $rows = $this->db->fetchAll(
            "SELECT * FROM media WHERE {$sqlWhere} ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset",
            $params + ['limit' => $perPage, 'offset' => max(0, ($page - 1) * $perPage)],
        );

        return [
            'items' => array_map(fn (array $row) => Media::fromRow($row, $this->baseUrl), $rows),
            'total' => $total,
        ];
    }

    /**
     * @param array<string, string> $variants
     */
    public function create(
        ?int $folderId,
        string $diskPath,
        string $originalName,
        string $mimeType,
        int $sizeBytes,
        ?int $width,
        ?int $height,
        string $altText,
        array $variants,
        ?int $uploadedBy,
    ): Media {
        $id = (int) $this->db->fetchValue(
            'INSERT INTO media (folder_id, disk_path, original_name, mime_type, size_bytes, width, height, alt_text, variants, uploaded_by)
             VALUES (:folder, :path, :name, :mime, :size, :width, :height, :alt, CAST(:variants AS jsonb), :user)
             RETURNING id',
            [
                'folder' => $folderId,
                'path' => $diskPath,
                'name' => $originalName,
                'mime' => $mimeType,
                'size' => $sizeBytes,
                'width' => $width,
                'height' => $height,
                'alt' => $altText,
                'variants' => json_encode($variants, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'user' => $uploadedBy,
            ],
        );

        return $this->find($id) ?? throw new \RuntimeException('Média introuvable après création.');
    }

    public function updateDetails(int $id, string $name, string $altText, ?int $folderId): void
    {
        $this->db->execute(
            'UPDATE media SET original_name = :name, alt_text = :alt, folder_id = :folder WHERE id = :id',
            ['name' => $name, 'alt' => $altText, 'folder' => $folderId, 'id' => $id],
        );
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM media WHERE id = :id', ['id' => $id]);
    }

    public function totalSize(): int
    {
        return (int) $this->db->fetchValue('SELECT COALESCE(sum(size_bytes), 0) FROM media');
    }
}
