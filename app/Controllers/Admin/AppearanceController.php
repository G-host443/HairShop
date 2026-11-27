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
use App\Services\Theme\AppearanceService;
use App\Services\Theme\FontCatalog;
use App\Services\Theme\TemplateManager;

/**
 * Apparence du site : choix du template et personnalisation (couleurs, polices, formes, animations, mise en page).
 */
final class AppearanceController extends Controller
{
    public function __construct(
        View $view,
        Router $router,
        Session $session,
        private readonly AppearanceService $appearance,
        private readonly TemplateManager $templates,
    ) {
        parent::__construct($view, $router, $session);
    }

    public function templates(): Response
    {
        return $this->render('admin/appearance/templates.twig', [
            'templates' => $this->appearance->templates(),
            'tab' => 'templates',
        ]);
    }

    public function activate(Request $request, string $slug): Response
    {
        $template = array_values(array_filter($this->appearance->templates(), static fn (array $t): bool => $t['slug'] === $slug))[0] ?? null;
        if ($template === null || !$template['installed']) {
            $this->flash('error', 'Ce template n\'est pas encore disponible.');

            return $this->redirectTo('admin.appearance');
        }

        $this->appearance->activate($slug, self::userId($request), $request);
        $this->flash('success', "Template « {$template['name']} » activé. Le contenu du site est inchangé ; ses propres réglages ont été retrouvés.");

        return $this->redirectTo('admin.appearance');
    }

    public function customize(): Response
    {
        $fonts = [];
        foreach (FontCatalog::FONTS as $name => [$category]) {
            $fonts[$category][] = $name;
        }

        return $this->render('admin/appearance/customize.twig', [
            'template' => $this->templates->manifest(),
            'groups' => $this->appearance->formFields(),
            'layout' => $this->appearance->effectiveLayout(),
            'layout_options' => $this->templates->manifest()->layout,
            'layout_labels' => AppearanceService::LAYOUT_LABELS,
            'motion_labels' => AppearanceService::MOTION_LABELS,
            'fonts' => $fonts,
            'font_categories' => ['serif' => 'Avec empattements', 'sans' => 'Sans empattements', 'script' => 'Manuscrites'],
            'warnings' => AppearanceService::contrastWarnings($this->appearance->effectiveTokens()),
            'has_draft' => $this->appearance->hasDraft(),
            'tab' => 'customize',
        ]);
    }

    public function save(Request $request): Response
    {
        $this->appearance->saveDraft((array) $request->input('tokens', []), (array) $request->input('layout', []));

        if ($request->input('then') === 'preview') {
            return $this->redirectTo('admin.appearance.preview');
        }
        $this->flash('success', 'Réglages enregistrés dans le brouillon : le site en ligne n\'a pas changé. Prévisualisez puis publiez.');

        return $this->redirectTo('admin.appearance.customize');
    }

    public function publish(Request $request): Response
    {
        $this->appearance->publish(self::userId($request), $request);
        $this->flash('success', 'Apparence publiée : elle est en ligne.');

        return $this->redirectTo('admin.appearance.customize');
    }

    public function discard(): Response
    {
        $this->appearance->discard();
        $this->flash('success', 'Modifications annulées : retour à l\'apparence en ligne.');

        return $this->redirectTo('admin.appearance.customize');
    }

    public function reset(): Response
    {
        $this->appearance->resetDraftToDefaults();
        $this->flash('success', 'Valeurs d\'origine du template remises dans le brouillon. Publiez pour les appliquer au site.');

        return $this->redirectTo('admin.appearance.customize');
    }

    private static function userId(Request $request): ?int
    {
        $user = $request->attribute('user');

        return $user instanceof User ? $user->id : null;
    }
}
