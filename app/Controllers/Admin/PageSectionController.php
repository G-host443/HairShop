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
use App\Services\Audit\AuditLogger;
use App\Services\Content\PageBuilder;

/**
 * Sections d'une page (constructeur) : ajout, édition, ordre, visibilité, duplication, suppression.
 * Tout reste en brouillon jusqu'à la publication de la page.
 */
final class PageSectionController extends Controller
{
    public function __construct(
        View $view,
        Router $router,
        Session $session,
        private readonly PageBuilder $builder,
        private readonly MediaRepository $media,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct($view, $router, $session);
    }

    public function store(Request $request, int $id): Response
    {
        $page = $this->builder->page($id);
        $sectionId = $this->builder->addSection((int) $page['id'], (string) $request->input('type', ''), self::userId($request), $request);
        $this->flash('success', 'Section ajoutée en fin de page. Complétez-la puis enregistrez.');

        return $this->redirectTo('admin.pages.sections.edit', ['id' => $id, 'sectionId' => $sectionId]);
    }

    public function edit(int $id, int $sectionId): Response
    {
        $page = $this->builder->page($id);
        $section = $this->builder->section($id, $sectionId);
        $type = $this->builder->type((string) $section['type']);
        $fields = $this->builder->fields($type);

        // Images déjà choisies (champs « media »), pour l'aperçu dans le formulaire.
        $images = [];
        foreach ($fields as $name => $field) {
            if ($field['type'] === 'media' && is_numeric($section['content'][$name] ?? null)) {
                $images[$name] = $this->media->find((int) $section['content'][$name]);
            }
        }

        return $this->render('admin/pages/section-edit.twig', [
            'page' => $page,
            'section' => $section,
            'type_label' => $type->label(),
            'fields' => $fields,
            'images' => $images,
            'variants' => $this->builder->variants((string) $section['type']),
            'variant_labels' => PageBuilder::VARIANT_LABELS,
            'current_variant' => $this->builder->currentVariant($section),
        ]);
    }

    public function update(Request $request, int $id, int $sectionId): Response
    {
        $section = $this->builder->section($id, $sectionId);
        $visible = $this->builder->updateSection($section, $request->all());
        $this->audit->log('content.section_updated', self::userId($request), $request, 'page_section', $sectionId);

        if ($visible) {
            $this->flash('success', 'Section enregistrée dans le brouillon. Prévisualisez puis publiez la page pour la mettre en ligne.');
        } else {
            $this->flash('warning', 'Section enregistrée, mais elle ne s\'affichera pas avec ce contenu (titre ou bouton manquant, aucune photo ou aucun avis publié…).');
        }

        return $request->input('then') === 'page'
            ? $this->redirectTo('admin.pages.edit', ['id' => $id])
            : $this->redirectTo('admin.pages.sections.edit', ['id' => $id, 'sectionId' => $sectionId]);
    }

    public function toggle(Request $request, int $id, int $sectionId): Response
    {
        $section = $this->builder->section($id, $sectionId);
        $this->builder->setVisibility($section, !$section['is_visible']);
        $this->flash('success', $section['is_visible'] ? 'Section masquée (dans le brouillon).' : 'Section affichée (dans le brouillon).');

        return $this->redirectTo('admin.pages.edit', ['id' => $id]);
    }

    public function duplicate(Request $request, int $id, int $sectionId): Response
    {
        $copy = $this->builder->duplicate($this->builder->section($id, $sectionId));
        $this->flash('success', 'Section dupliquée juste en dessous.');

        return $this->redirectTo('admin.pages.sections.edit', ['id' => $id, 'sectionId' => $copy]);
    }

    public function destroy(Request $request, int $id, int $sectionId): Response
    {
        $section = $this->builder->section($id, $sectionId);
        $this->builder->removeSection($section);
        $this->audit->log('content.section_removed', self::userId($request), $request, 'page_section', $sectionId);
        $this->flash('success', $section['is_new'] ? 'Section supprimée.' : 'Section retirée : elle disparaîtra du site à la prochaine publication (restaurable d\'ici là).');

        return $this->redirectTo('admin.pages.edit', ['id' => $id]);
    }

    public function restore(Request $request, int $id, int $sectionId): Response
    {
        $this->builder->restoreSection($this->builder->section($id, $sectionId));
        $this->flash('success', 'Section restaurée en fin de page.');

        return $this->redirectTo('admin.pages.edit', ['id' => $id]);
    }

    public function reorder(Request $request, int $id): Response
    {
        $page = $this->builder->page($id);
        $this->builder->reorder((int) $page['id'], CategoryController::idList($request->input('ids')));

        return Response::json(['ok' => true]);
    }

    private static function userId(Request $request): ?int
    {
        $user = $request->attribute('user');

        return $user instanceof User ? $user->id : null;
    }
}
