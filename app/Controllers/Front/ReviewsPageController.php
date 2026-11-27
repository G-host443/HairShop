<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\MediaRepository;
use App\Repositories\ReviewRepository;
use App\Services\Content\PageRenderer;

/**
 * Tous les avis publiés (/avis), les plus récents d'abord.
 */
final class ReviewsPageController
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly ReviewRepository $reviews,
        private readonly MediaRepository $media,
        private readonly PageRenderer $renderer,
    ) {
    }

    public function index(Request $request): Response
    {
        $stats = $this->reviews->approvedStats();
        $pages = max(1, (int) ceil($stats['count'] / self::PER_PAGE));
        $page = min($pages, max(1, (int) filter_var($request->query('page'), FILTER_VALIDATE_INT)));

        $rows = $this->reviews->approved(self::PER_PAGE, ($page - 1) * self::PER_PAGE, false);
        $photos = $this->media->findMany(array_filter(array_map(static fn (array $r) => $r['photo_media_id'] !== null ? (int) $r['photo_media_id'] : null, $rows)));

        return Response::html($this->renderer->renderView('reviews', [
            'reviews' => array_map(static fn (array $r): array => $r + ['photo' => $r['photo_media_id'] !== null ? ($photos[(int) $r['photo_media_id']] ?? null) : null], $rows),
            'stats' => $stats,
            'distribution' => $this->reviews->ratingDistribution(),
            'page' => $page,
            'pages' => $pages,
        ], [
            'title' => 'Avis clientes' . ($page > 1 ? " — page {$page}" : ''),
            'canonical_query' => $page > 1 ? 'page=' . $page : '',
            'seo_description' => $stats['count'] > 0
                ? sprintf('Note moyenne de %s/5 sur %d avis de clientes.', number_format($stats['average'], 1, ',', ''), $stats['count'])
                : 'Les avis des clientes de Hair_style by Garance.',
        ], '/avis'));
    }
}
