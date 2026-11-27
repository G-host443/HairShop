<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\GalleryRepository;
use App\Repositories\MediaRepository;
use App\Services\Content\PageRenderer;

/**
 * Portfolio public (/galerie) : filtres par catégorie et tag, recherche, affichage en grille ou en mosaïque.
 * Tout fonctionne par liens et formulaire GET : rien n'exige JavaScript.
 */
final class GalleryPageController
{
    private const PER_PAGE = 24;

    public function __construct(
        private readonly GalleryRepository $gallery,
        private readonly MediaRepository $media,
        private readonly PageRenderer $renderer,
    ) {
    }

    public function index(Request $request): Response
    {
        $categories = $this->gallery->publishedCategories();
        $categorySlug = self::param($request, 'categorie');
        $category = array_values(array_filter($categories, static fn (array $c): bool => $c['slug'] === $categorySlug))[0] ?? null;
        $tag = mb_strtolower(self::param($request, 'tag'));
        $q = mb_substr(self::param($request, 'q'), 0, 60);
        $view = self::param($request, 'vue') === 'mosaique' ? 'mosaique' : 'grille';
        $page = max(1, (int) filter_var($request->query('page'), FILTER_VALIDATE_INT));

        $filters = ['category' => $category['id'] ?? null, 'tag' => $tag !== '' ? $tag : null, 'q' => $q !== '' ? $q : null];
        $total = $this->gallery->countPublished($filters);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        $rows = $this->gallery->published($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $media = $this->media->findMany(array_merge(array_column($rows, 'media_id'), array_filter(array_column($rows, 'after_media_id'))));
        $items = [];
        foreach ($rows as $row) {
            $image = $media[$row['media_id']] ?? null;
            if ($image === null || !$image->isImage()) {
                continue;
            }
            $after = $row['after_media_id'] !== null ? ($media[$row['after_media_id']] ?? null) : null;
            $items[] = $row + ['image' => $image, 'after' => $after?->isImage() ? $after : null];
        }

        // Paramètres conservés d'un lien de filtre à l'autre.
        $query = array_filter(['categorie' => $category['slug'] ?? null, 'tag' => $filters['tag'], 'q' => $filters['q'], 'vue' => $view === 'mosaique' ? 'mosaique' : null]);

        $title = 'Réalisations' . ($category !== null ? ' — ' . $category['name'] : '');

        return Response::html($this->renderer->renderView('gallery', [
            'items' => $items,
            'categories' => $categories,
            'tags' => $this->gallery->publishedTags(),
            'current' => ['category' => $category['slug'] ?? null, 'tag' => $filters['tag'], 'q' => $filters['q'] ?? '', 'view' => $view],
            'query' => $query,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ], [
            'title' => $title . ($page > 1 ? " — page {$page}" : ''),
            'seo_description' => 'Tresses, nattes et coiffures protectrices réalisées par Hair_style by Garance : photos, avant / après, par catégorie.',
            // Une catégorie (et sa pagination) est une page à part entière ; recherche, tag et affichage mosaïque ne le sont pas.
            'canonical_query' => http_build_query(array_filter(['categorie' => $category['slug'] ?? null, 'page' => $page > 1 ? $page : null])),
            'robots' => $filters['tag'] !== null || $filters['q'] !== null || $view === 'mosaique' ? 'noindex, follow' : null,
        ], '/galerie'));
    }

    private static function param(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? trim($value) : '';
    }
}
