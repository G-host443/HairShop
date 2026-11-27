<?php

declare(strict_types=1);

namespace App\Sections;

use App\Repositories\MediaRepository;
use App\Repositories\ReviewRepository;

final class TestimonialsSection implements SectionType
{
    public function __construct(
        private readonly ReviewRepository $reviews,
        private readonly MediaRepository $media,
    ) {
    }

    public function type(): string
    {
        return 'testimonials';
    }

    public function label(): string
    {
        return 'Avis clientes';
    }

    public function fields(): array
    {
        return [
            'anchor' => ['label' => 'Ancre (lien du menu)', 'type' => 'anchor'],
            'title' => ['label' => 'Titre', 'type' => 'text', 'max' => 80],
            'limit' => ['label' => 'Nombre d\'avis', 'type' => 'int', 'default' => 6, 'min' => 1, 'max' => 24],
            'show_link' => ['label' => 'Lien « Lire tous les avis »', 'type' => 'bool', 'default' => true],
        ];
    }

    public function prepare(array $content): ?array
    {
        $rows = $this->reviews->approved(Content::int($content, 'limit', 6, 1, 24));
        if ($rows === []) {
            return null;
        }

        $photos = $this->media->findMany(array_values(array_filter(array_map(
            static fn (array $r): ?int => $r['photo_media_id'] !== null ? (int) $r['photo_media_id'] : null,
            $rows,
        ))));
        $stats = $this->reviews->approvedStats();

        return [
            'title' => Content::text($content, 'title', 'Avis'),
            'reviews' => array_map(static function (array $r) use ($photos): array {
                $photo = $r['photo_media_id'] !== null ? ($photos[(int) $r['photo_media_id']] ?? null) : null;

                return [
                    'author' => (string) $r['author_name'],
                    'rating' => (int) $r['rating'],
                    'comment' => (string) $r['comment'],
                    'date' => (string) $r['created_at'],
                    'service' => $r['service_label'] !== null ? (string) $r['service_label'] : null,
                    'photo' => $photo?->isImage() ? $photo : null,
                ];
            }, $rows),
            'average' => $stats['average'],
            'count' => $stats['count'],
            'show_link' => Content::bool($content, 'show_link', true),
        ];
    }
}
