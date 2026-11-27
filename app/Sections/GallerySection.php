<?php

declare(strict_types=1);

namespace App\Sections;

use App\Core\Database;
use App\Repositories\MediaRepository;

final class GallerySection implements SectionType
{
    public function __construct(
        private readonly Database $db,
        private readonly MediaRepository $media,
    ) {
    }

    public function type(): string
    {
        return 'gallery';
    }

    public function label(): string
    {
        return 'Galerie de réalisations';
    }

    public function fields(): array
    {
        return [
            'anchor' => ['label' => 'Ancre (lien du menu)', 'type' => 'anchor'],
            'title' => ['label' => 'Titre', 'type' => 'text', 'max' => 80],
            'intro' => ['label' => 'Introduction', 'type' => 'textarea', 'max' => 400],
            'limit' => ['label' => 'Nombre de photos', 'type' => 'int', 'default' => 8, 'min' => 1, 'max' => 48],
            'featured_only' => ['label' => 'Uniquement les réalisations mises en avant', 'type' => 'bool'],
            'show_link' => ['label' => 'Lien « Voir toutes les réalisations »', 'type' => 'bool', 'default' => true],
        ];
    }

    public function prepare(array $content): ?array
    {
        $limit = Content::int($content, 'limit', 8, 1, 48);
        $featuredOnly = Content::bool($content, 'featured_only');

        $rows = $this->db->fetchAll(
            'SELECT id, media_id, after_media_id, title, description FROM gallery_items
             WHERE is_published AND (NOT CAST(:featured AS boolean) OR is_featured)
             ORDER BY is_featured DESC, position, id DESC
             LIMIT :limit',
            ['featured' => $featuredOnly, 'limit' => $limit],
        );

        // Si aucune réalisation n'est mise en avant, on montre les plus récentes.
        if ($rows === [] && $featuredOnly) {
            $rows = $this->db->fetchAll(
                'SELECT id, media_id, after_media_id, title, description FROM gallery_items
                 WHERE is_published ORDER BY position, id DESC LIMIT :limit',
                ['limit' => $limit],
            );
        }

        $media = $this->media->findMany(array_merge(array_column($rows, 'media_id'), array_filter(array_column($rows, 'after_media_id'))));
        $items = [];
        foreach ($rows as $row) {
            $image = $media[(int) $row['media_id']] ?? null;
            if ($image === null || !$image->isImage()) {
                continue;
            }
            $items[] = [
                'title' => (string) $row['title'],
                'description' => $row['description'] !== null ? (string) $row['description'] : '',
                'image' => $image,
                'after' => $row['after_media_id'] !== null ? ($media[(int) $row['after_media_id']] ?? null) : null,
            ];
        }

        if ($items === []) {
            return null;
        }

        return [
            'title' => Content::text($content, 'title', 'Réalisations'),
            'intro' => Content::text($content, 'intro'),
            'items' => $items,
            'show_link' => Content::bool($content, 'show_link', true),
        ];
    }
}
