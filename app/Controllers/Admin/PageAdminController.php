<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Router;
use App\Core\Session\Session;
use App\Core\View;
use App\Models\User;
use App\Repositories\MediaRepository;
use App\Repositories\PageRepository;
use App\Services\Content\PageBuilder;

/**
 * Pages du site : liste, création, réglages (titre, adresse, référencement), publication.
 */
final class PageAdminController extends Controller
{
    public const KIND_LABELS = ['home' => 'Accueil', 'system' => 'Page du site', 'standard' => 'Page de contenu', 'legal' => 'Page légale'];

    public function __construct(
        View $view,
        Router $router,
        Session $session,
        private readonly PageRepository $pages,
        private readonly PageBuilder $builder,
        private readonly MediaRepository $media,
    ) {
        parent::__construct($view, $router, $session);
    }

    public function index(): Response
    {
        return $this->render('admin/pages/index.twig', [
            'pages' => $this->pages->all(),
            'kinds' => self::KIND_LABELS,
            'creatable_kinds' => PageBuilder::CREATABLE_KINDS,
        ]);
    }

    public function store(Request $request): Response
    {
        $id = $this->builder->createPage(
            (string) $request->input('title', ''),
            (string) $request->input('slug', ''),
            (string) $request->input('kind', 'standard'),
            self::userId($request),
            $request,
        );
        $this->flash('success', 'Page créée en brouillon. Ajoutez des sections, prévisualisez, puis publiez.');

        return $this->redirectTo('admin.pages.edit', ['id' => $id]);
    }

    public function edit(int $id): Response
    {
        $page = $this->builder->page($id);
        $og = $page['og_media_id'] !== null ? $this->media->find((int) $page['og_media_id']) : null;

        return $this->render('admin/pages/edit.twig', [
            'page' => $page,
            'kind_label' => self::KIND_LABELS[$page['kind']] ?? $page['kind'],
            'sections' => $this->builder->sectionsForEditor($id),
            'types' => $this->builder->availableTypes(),
            'slug_locked' => PageBuilder::slugLocked($page),
            'deletable' => PageBuilder::deletable($page),
            'public_url' => $page['kind'] === 'home' ? '/' : '/' . $page['slug'],
            'og_image' => $og,
        ]);
    }

    public function update(Request $request, int $id): Response
    {
        $this->builder->updateMeta($this->builder->page($id), $request->all(), self::userId($request), $request);
        $this->flash('success', 'Réglages de la page enregistrés.');

        return $this->redirectTo('admin.pages.edit', ['id' => $id]);
    }

    public function publish(Request $request, int $id): Response
    {
        $page = $this->builder->page($id);
        $this->builder->publish($page, self::userId($request), $request);
        $this->flash('success', $page['status'] === 'published' ? 'Modifications publiées : elles sont en ligne.' : 'Page publiée : elle est en ligne.');

        return $this->redirectTo('admin.pages.edit', ['id' => $id]);
    }

    public function discard(Request $request, int $id): Response
    {
        $this->builder->discard($this->builder->page($id), self::userId($request), $request);
        $this->flash('success', 'Modifications annulées : la page est revenue à sa version en ligne.');

        return $this->redirectTo('admin.pages.edit', ['id' => $id]);
    }

    public function unpublish(Request $request, int $id): Response
    {
        $this->builder->unpublish($this->builder->page($id), self::userId($request), $request);
        $this->flash('success', 'Page retirée du site. Elle reste modifiable ici.');

        return $this->redirectTo('admin.pages.edit', ['id' => $id]);
    }

    public function destroy(Request $request, int $id): Response
    {
        $page = $this->builder->page($id);
        $this->builder->deletePage($page, self::userId($request), $request);
        $this->flash('success', "Page « {$page['title']} » supprimée.");

        return $this->redirectTo('admin.pages');
    }

    private static function userId(Request $request): ?int
    {
        $user = $request->attribute('user');

        return $user instanceof User ? $user->id : null;
    }
}
