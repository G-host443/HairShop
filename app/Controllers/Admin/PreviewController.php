<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Exceptions\HttpException;
use App\Core\Http\Response;
use App\Core\Router;
use App\Core\Session\Session;
use App\Core\View;
use App\Repositories\PageRepository;
use App\Services\Content\PageBuilder;
use App\Services\Content\PageRenderer;
use App\Services\Content\PreviewContext;
use App\Services\Theme\AppearanceService;

/**
 * Aperçu avant publication : un cadre (iframe) affiche la page rendue avec les brouillons,
 * en largeur ordinateur ou mobile (390 px). Réservé à l'administration (routes protégées).
 */
final class PreviewController extends Controller
{
    public function __construct(
        View $view,
        Router $router,
        Session $session,
        private readonly PageBuilder $builder,
        private readonly PageRepository $pages,
        private readonly PageRenderer $renderer,
        private readonly PreviewContext $preview,
        private readonly AppearanceService $appearance,
    ) {
        parent::__construct($view, $router, $session);
    }

    /** Écran d'aperçu d'une page (brouillon du contenu). */
    public function page(int $id): Response
    {
        $page = $this->builder->page($id);

        return $this->render('admin/preview.twig', [
            'title' => 'Aperçu : ' . $page['title'],
            'frame_url' => $this->router->url('admin.pages.preview.frame', ['id' => $id]),
            'back_url' => $this->router->url('admin.pages.edit', ['id' => $id]),
            'back_label' => 'Retour à la page',
            'publish_url' => $this->router->url('admin.pages.publish', ['id' => $id]),
            'can_publish' => (bool) $page['has_unpublished_changes'] || $page['status'] !== 'published',
            'note' => $page['status'] === 'published' ? null : 'Cette page n\'est pas encore en ligne.',
            'current_route' => 'admin.pages',
        ]);
    }

    /** Contenu du cadre : la page avec ses brouillons. */
    public function pageFrame(int $id): Response
    {
        $page = $this->builder->page($id);
        $this->preview->enableDrafts();

        return self::noStore($this->renderer->render($page, $page['kind'] === 'home' ? '/' : '/' . $page['slug']));
    }

    /** Écran d'aperçu de l'apparence (réglages du template en brouillon). */
    public function theme(): Response
    {
        return $this->render('admin/preview.twig', [
            'title' => 'Aperçu de l\'apparence',
            'frame_url' => $this->router->url('admin.appearance.preview.frame'),
            'back_url' => $this->router->url('admin.appearance.customize'),
            'back_label' => 'Retour à la personnalisation',
            'publish_url' => $this->router->url('admin.appearance.publish'),
            'can_publish' => $this->appearance->hasDraft(),
            'note' => $this->appearance->hasDraft() ? null : 'Aucune modification en attente : vous voyez l\'apparence en ligne.',
            'current_route' => 'admin.appearance',
        ]);
    }

    /** Contenu du cadre : l'accueil publié, avec l'apparence en brouillon. */
    public function themeFrame(): Response
    {
        $this->preview->enableTheme();
        $home = $this->pages->findPublishedHome() ?? throw HttpException::notFound('Publiez d\'abord la page d\'accueil.');

        return self::noStore($this->renderer->render($home, '/'));
    }

    /** Écran d'aperçu d'un template avant activation. */
    public function template(string $slug): Response
    {
        $template = $this->installedTemplate($slug);

        return $this->render('admin/preview.twig', [
            'title' => 'Essai du template « ' . $template['name'] . ' »',
            'frame_url' => $this->router->url('admin.appearance.template.frame', ['slug' => $slug]),
            'back_url' => $this->router->url('admin.appearance'),
            'back_label' => 'Retour aux templates',
            'publish_url' => $this->router->url('admin.appearance.activate', ['slug' => $slug]),
            'publish_label' => 'Activer ce template',
            'can_publish' => !$template['is_active'],
            'note' => 'Votre contenu actuel, présenté avec ce template. Rien ne change sur le site tant que vous ne l\'activez pas.',
            'current_route' => 'admin.appearance',
        ]);
    }

    /** Contenu du cadre : l'accueil publié, rendu avec le template essayé (et ses propres réglages). */
    public function templateFrame(string $slug): Response
    {
        $this->installedTemplate($slug);
        $this->preview->useTemplate($slug);
        $home = $this->pages->findPublishedHome() ?? throw HttpException::notFound('Publiez d\'abord la page d\'accueil.');

        return self::noStore($this->renderer->render($home, '/'));
    }

    /** @return array{slug: string, name: string, is_active: bool, installed: bool} */
    private function installedTemplate(string $slug): array
    {
        foreach ($this->appearance->templates() as $template) {
            if ($template['slug'] === $slug && $template['installed']) {
                return $template;
            }
        }

        throw HttpException::notFound('Ce template n\'est pas disponible.');
    }

    private static function noStore(string $html): Response
    {
        return Response::html($html)
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
