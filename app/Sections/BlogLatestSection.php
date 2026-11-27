<?php

declare(strict_types=1);

namespace App\Sections;

use App\Core\Clock;
use App\Repositories\BlogRepository;
use App\Repositories\MediaRepository;
use App\Services\Blog\BlogService;

/**
 * Derniers articles du blog. Sans article publié, la section ne s'affiche pas.
 */
final class BlogLatestSection implements SectionType
{
    public function __construct(
        private readonly BlogRepository $blog,
        private readonly MediaRepository $media,
        private readonly Clock $clock,
    ) {
    }

    public function type(): string
    {
        return 'blog_latest';
    }

    public function label(): string
    {
        return 'Derniers articles du blog';
    }

    public function fields(): array
    {
        return [
            'anchor' => ['label' => 'Ancre (lien du menu)', 'type' => 'anchor'],
            'title' => ['label' => 'Titre', 'type' => 'text', 'max' => 80],
            'intro' => ['label' => 'Introduction', 'type' => 'textarea', 'max' => 300],
            'limit' => ['label' => 'Nombre d\'articles', 'type' => 'int', 'default' => 3, 'min' => 1, 'max' => 9],
        ];
    }

    public function prepare(array $content): ?array
    {
        $posts = $this->blog->visible($this->clock->now()->format(DATE_ATOM), [], Content::int($content, 'limit', 3, 1, 9));
        if ($posts === []) {
            return null;
        }

        return [
            'title' => Content::text($content, 'title', 'Conseils et actualités'),
            'intro' => Content::text($content, 'intro'),
            'posts' => array_map(static fn (array $p): array => $p + [
                'summary' => BlogService::excerpt($p, 140),
                'reading_minutes' => BlogService::readingMinutes((string) $p['content']),
            ], $posts),
            'covers' => $this->media->findMany(array_values(array_filter(array_map(static fn (array $p) => $p['cover_media_id'] !== null ? (int) $p['cover_media_id'] : null, $posts)))),
        ];
    }
}
