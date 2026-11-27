<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Clock;
use App\Core\Exceptions\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Router;
use App\Core\Session\Session;
use App\Core\View;
use App\Models\User;
use App\Repositories\BlogRepository;
use App\Repositories\MediaRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Blog\BlogService;
use App\Services\Content\PageRenderer;
use App\Services\Content\PreviewContext;
use DateTimeImmutable;

/**
 * Articles du blog : liste, rédaction (éditeur riche), brouillon, publication immédiate ou programmée, aperçu.
 */
final class BlogPostController extends Controller
{
    public const STATUS_LABELS = ['draft' => ['Brouillon', 'secondary'], 'scheduled' => ['Programmé', 'info'], 'published' => ['Publié', 'success']];

    public function __construct(
        View $view,
        Router $router,
        Session $session,
        private readonly BlogRepository $blog,
        private readonly BlogService $service,
        private readonly MediaRepository $media,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly PageRenderer $renderer,
        private readonly PreviewContext $preview,
    ) {
        parent::__construct($view, $router, $session);
    }

    public function index(Request $request): Response
    {
        $filters = [
            'status' => is_string($request->query('statut')) ? $request->query('statut') : null,
            'category' => filter_var($request->query('categorie'), FILTER_VALIDATE_INT) ?: null,
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 100),
        ];
        $posts = $this->blog->adminList($filters);
        $now = $this->clock->now();

        return $this->render('admin/blog/index.twig', [
            'posts' => array_map(static fn (array $p): array => $p + [
                // Programmé mais date passée (la tâche planifiée n'est pas encore passée) : déjà visible.
                'is_live' => $p['status'] !== 'draft' && $p['published_at'] !== null && new DateTimeImmutable((string) $p['published_at']) <= $now,
            ], $posts),
            'covers' => $this->media->findMany(array_values(array_filter(array_map(static fn (array $p) => $p['cover_media_id'] !== null ? (int) $p['cover_media_id'] : null, $posts)))),
            'categories' => $this->blog->categories(),
            'filters' => $filters,
            'counts' => $this->blog->statusCounts(),
            'status_labels' => self::STATUS_LABELS,
        ]);
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function store(Request $request): Response
    {
        $id = $this->service->save(null, $request->all(), self::userId($request), $request);
        $this->flash('success', $this->savedMessage($id));

        return $this->redirectTo('admin.blog.edit', ['id' => $id]);
    }

    public function edit(int $id): Response
    {
        return $this->form($this->blog->find($id) ?? throw HttpException::notFound());
    }

    public function update(Request $request, int $id): Response
    {
        $post = $this->blog->find($id) ?? throw HttpException::notFound();
        $this->service->save($post, $request->all(), self::userId($request), $request);
        $this->flash('success', $this->savedMessage($id));

        return $this->redirectTo('admin.blog.edit', ['id' => $id]);
    }

    public function destroy(Request $request, int $id): Response
    {
        $post = $this->blog->find($id) ?? throw HttpException::notFound();
        $this->blog->delete($id);
        $this->audit->log('blog.post_deleted', self::userId($request), $request, 'blog_post', $id, ['title' => $post['title']]);
        $this->flash('success', "Article « {$post['title']} » supprimé.");

        return $this->redirectTo('admin.blog');
    }

    /** Aperçu de l'article tel qu'il apparaîtra, même s'il n'est pas publié. */
    public function preview(int $id): Response
    {
        $post = $this->blog->find($id) ?? throw HttpException::notFound();
        $this->preview->enableDrafts();
        $media = $post['cover_media_id'] !== null ? $this->media->find((int) $post['cover_media_id']) : null;

        return Response::html($this->renderer->renderView('blog-post', [
            'post' => $post,
            'cover' => $media?->isImage() ? $media : null,
            'tags' => $this->blog->postTags($id),
            'reading_minutes' => BlogService::readingMinutes((string) $post['content']),
            'related' => [],
            'related_covers' => [],
        ], ['title' => (string) $post['title']], '/blog/' . $post['slug']))
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    private function form(?array $post): Response
    {
        $published = $post !== null && $post['published_at'] !== null
            ? (new DateTimeImmutable((string) $post['published_at']))->setTimezone($this->clock->zone())
            : null;
        $tomorrow = $this->clock->now()->modify('+1 day');

        return $this->render('admin/blog/form.twig', [
            'post' => $post,
            'cover' => $post !== null && $post['cover_media_id'] !== null ? $this->media->find((int) $post['cover_media_id']) : null,
            'categories' => $this->blog->categories(),
            'tags' => $post !== null ? implode(', ', array_column($this->blog->postTags((int) $post['id']), 'name')) : '',
            'all_tags' => array_column($this->blog->tags(), 'name'),
            'publication' => match ($post['status'] ?? 'draft') {
                'published' => 'now',
                'scheduled' => 'schedule',
                default => 'draft',
            },
            'publish_date' => ($post !== null && $post['status'] === 'scheduled' && $published !== null ? $published : $tomorrow)->format('Y-m-d'),
            'publish_time' => ($post !== null && $post['status'] === 'scheduled' && $published !== null ? $published->format('H:i') : '09:00'),
            'published_label' => $published,
            'choices' => BlogService::PUBLICATION_CHOICES,
            'status_labels' => self::STATUS_LABELS,
        ]);
    }

    private function savedMessage(int $id): string
    {
        $post = $this->blog->find($id);

        return match ($post['status'] ?? 'draft') {
            'published' => 'Article enregistré et publié.',
            'scheduled' => 'Article programmé : il sera en ligne le ' . (new DateTimeImmutable((string) $post['published_at']))->setTimezone($this->clock->zone())->format('d/m/Y à H:i') . '.',
            default => 'Brouillon enregistré (non visible sur le site).',
        };
    }

    private static function userId(Request $request): ?int
    {
        $user = $request->attribute('user');

        return $user instanceof User ? $user->id : null;
    }
}
