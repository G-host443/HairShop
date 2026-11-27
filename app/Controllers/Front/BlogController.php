<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\Clock;
use App\Core\Exceptions\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\BlogRepository;
use App\Repositories\MediaRepository;
use App\Services\Blog\BlogService;
use App\Services\Content\PageRenderer;
use App\Services\Seo\SeoService;

/**
 * Blog public : liste des articles (toutes, par catégorie, par tag) et page d'un article.
 */
final class BlogController
{
    private const PER_PAGE = 9;

    public function __construct(
        private readonly BlogRepository $blog,
        private readonly MediaRepository $media,
        private readonly PageRenderer $renderer,
        private readonly Clock $clock,
        private readonly SeoService $seo,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->listing($request, [], 'Conseils et actualités', '/blog');
    }

    public function category(Request $request, string $slug): Response
    {
        $category = $this->blog->findCategoryBySlug($slug) ?? throw HttpException::notFound('Cette catégorie n\'existe pas.');

        return $this->listing($request, ['category' => (int) $category['id']], (string) $category['name'], '/blog/categorie/' . $slug, ['category' => $category]);
    }

    public function tag(Request $request, string $slug): Response
    {
        $tag = $this->blog->findTagBySlug($slug) ?? throw HttpException::notFound('Ce tag n\'existe pas.');

        return $this->listing($request, ['tag' => (int) $tag['id']], '#' . $tag['name'], '/blog/tag/' . $slug, ['tag' => $tag]);
    }

    public function show(string $slug): Response
    {
        $now = $this->now();
        $post = $this->blog->findVisibleBySlug($slug, $now) ?? throw HttpException::notFound('Cet article n\'existe pas ou n\'est pas encore publié.');
        $cover = $post['cover_media_id'] !== null ? $this->media->find((int) $post['cover_media_id']) : null;

        // Articles à lire ensuite : même catégorie d'abord, sinon les plus récents.
        $related = $post['category_id'] !== null ? $this->blog->visible($now, ['category' => (int) $post['category_id']], 3, 0, (int) $post['id']) : [];
        if (count($related) < 3) {
            $ids = array_merge([(int) $post['id']], array_map('intval', array_column($related, 'id')));
            foreach ($this->blog->visible($now, [], 6, 0, (int) $post['id']) as $other) {
                if (count($related) < 3 && !in_array((int) $other['id'], $ids, true)) {
                    $related[] = $other;
                    $ids[] = (int) $other['id'];
                }
            }
        }

        return Response::html($this->renderer->renderView('blog-post', [
            'post' => $post,
            'cover' => $cover?->isImage() ? $cover : null,
            'tags' => $this->blog->postTags((int) $post['id']),
            'reading_minutes' => BlogService::readingMinutes((string) $post['content']),
            'related' => self::cards($related),
            'related_covers' => $this->covers($related),
        ], [
            'title' => (string) $post['title'],
            'seo_title' => $post['seo_title'],
            'seo_description' => $description = ($post['seo_description'] ?? BlogService::excerpt($post, 160)),
            'og_media_id' => $post['cover_media_id'] !== null ? (int) $post['cover_media_id'] : null,
            'og_type' => 'article',
            'published_time' => (new \DateTimeImmutable((string) $post['published_at']))->format(DATE_ATOM),
            'json_ld' => [
                $this->seo->articleJsonLd($post, $cover?->isImage() ? $this->seo->baseUrl() . $cover->url('large') : null, $description),
                $this->seo->breadcrumbJsonLd(array_values(array_filter([
                    ['Blog', '/blog'],
                    $post['category_slug'] !== null ? [(string) $post['category_name'], '/blog/categorie/' . $post['category_slug']] : null,
                    [(string) $post['title'], '/blog/' . $slug],
                ]))),
            ],
        ], '/blog/' . $slug));
    }

    /** @param array{category?: int, tag?: int} $filters */
    private function listing(Request $request, array $filters, string $title, string $path, array $extra = []): Response
    {
        $now = $this->now();
        $total = $this->blog->countVisible($now, $filters);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) filter_var($request->query('page'), FILTER_VALIDATE_INT)));
        $posts = $this->blog->visible($now, $filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        return Response::html($this->renderer->renderView('blog', $extra + [
            'posts' => self::cards($posts),
            'covers' => $this->covers($posts),
            'categories' => $this->blog->visibleCategories($now),
            'current_category' => isset($extra['category']) ? $extra['category']['slug'] : null,
            'tag' => $extra['tag'] ?? null,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'path' => $path,
        ], [
            'title' => $title . ($page > 1 ? " — page {$page}" : ''),
            'seo_description' => 'Conseils d\'entretien, inspirations et actualités autour des tresses et des coiffures protectrices.',
            'canonical_query' => $page > 1 ? 'page=' . $page : '',
            // Pages de tag : contenu redondant avec les catégories, non indexées (liens suivis).
            'robots' => isset($extra['tag']) ? 'noindex, follow' : null,
        ], $path));
    }

    private function now(): string
    {
        return $this->clock->now()->format(DATE_ATOM);
    }

    /** @param list<array<string, mixed>> $posts */
    private static function cards(array $posts): array
    {
        return array_map(static fn (array $p): array => $p + [
            'summary' => BlogService::excerpt($p),
            'reading_minutes' => BlogService::readingMinutes((string) $p['content']),
        ], $posts);
    }

    /** @param list<array<string, mixed>> $posts */
    private function covers(array $posts): array
    {
        return $this->media->findMany(array_values(array_filter(array_map(static fn (array $p) => $p['cover_media_id'] !== null ? (int) $p['cover_media_id'] : null, $posts))));
    }
}
