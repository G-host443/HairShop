<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Core\Config;
use App\Core\View;
use App\Core\Clock;
use App\Repositories\BlogRepository;
use App\Repositories\MediaRepository;
use App\Repositories\PageRepository;
use App\Sections\Content;
use App\Sections\SectionRegistry;
use App\Services\Seo\SeoService;
use App\Services\Settings\SettingsService;
use App\Services\Theme\TemplateManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Rendu d'une page publique : sections (contenu commun) → gabarits du template actif → mise en page.
 * Une section en erreur est journalisée et ignorée : elle ne fait jamais tomber la page entière.
 */
final class PageRenderer
{
    /** @var list<array<string, mixed>>|null sections préparées de l'accueil (navigation) */
    private ?array $homeSections = null;

    public function __construct(
        private readonly PageRepository $pages,
        private readonly SectionRegistry $registry,
        private readonly TemplateManager $templates,
        private readonly View $view,
        private readonly SettingsService $settings,
        private readonly MediaRepository $media,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly PreviewContext $preview,
        private readonly BlogRepository $blog,
        private readonly Clock $clock,
        private readonly SeoService $seoService,
    ) {
    }

    /**
     * @param array<string, mixed> $page    ligne de la table pages
     * @param array<string, mixed> $context variables supplémentaires pour les sections (ex. message pré-rempli)
     */
    public function render(array $page, string $path, array $context = []): string
    {
        $this->templates->register();
        $isHome = $page['kind'] === 'home';

        $prepared = $isHome ? $this->homeSections($page) : $this->prepareSections((int) $page['id']);
        $anchors = array_values(array_filter(array_column($prepared, 'anchor')));

        $html = [];
        foreach ($prepared as $section) {
            try {
                $html[] = $this->view->render($section['template'], $section['data'] + [
                    'section' => [
                        'id' => $section['id'],
                        'type' => $section['type'],
                        'anchor' => $section['anchor'],
                        'settings' => $section['settings'],
                    ],
                    'anchors' => $anchors,
                    'is_home' => $isHome,
                    'page_path' => $path,
                ] + $context);
            } catch (Throwable $e) {
                $this->logger->error("Rendu de la section #{$section['id']} ({$section['type']}) impossible : " . $e->getMessage(), ['exception' => $e]);
            }
        }

        return $this->view->render('@theme/layouts/page.twig', [
            'page' => $page,
            'is_home' => $isHome,
            'sections' => $html,
            'navigation' => $this->navigation($isHome),
            'footer_pages' => $this->pages->footerPages(),
            'seo' => $this->seo($page, $isHome, $path, $isHome ? ['json_ld' => [$this->seoService->businessJsonLd()]] : []),
            'has_contact_page' => $this->pages->findPublishedBySlug('contact') !== null,
            'has_blog' => $this->hasBlog(),
        ]);
    }

    /**
     * Page spécifique (fiche prestation, liste…) rendue dans la mise en page du template actif.
     * Gabarit cherché dans le template (@theme/pages/<nom>.twig), sinon dans resources/views/pages/.
     *
     * @param array<string, mixed>                                             $data
     * @param array{title: string, seo_title?: ?string, seo_description?: ?string, og_media_id?: ?int, robots?: ?string, json_ld?: list<array<string, mixed>>, og_type?: string, canonical_query?: string, published_time?: ?string} $meta
     */
    public function renderView(string $name, array $data, array $meta, string $path): string
    {
        $this->templates->register();
        $template = $this->view->exists("@theme/pages/{$name}.twig") ? "@theme/pages/{$name}.twig" : "pages/{$name}.twig";

        $page = [
            'id' => 0,
            'slug' => trim($path, '/'),
            'title' => $meta['title'],
            'kind' => 'view',
            'seo_title' => $meta['seo_title'] ?? null,
            'seo_description' => $meta['seo_description'] ?? null,
            'og_media_id' => $meta['og_media_id'] ?? null,
        ];

        return $this->view->render('@theme/layouts/page.twig', [
            'page' => $page,
            'is_home' => false,
            'sections' => [$this->view->render($template, $data + ['page_path' => $path])],
            'navigation' => $this->navigation(false),
            'footer_pages' => $this->pages->footerPages(),
            'seo' => $this->seo($page, false, $path, $meta),
            'has_contact_page' => $this->pages->findPublishedBySlug('contact') !== null,
            'has_blog' => $this->hasBlog(),
        ]);
    }

    private ?bool $hasBlog = null;

    /**
     * Variante demandée par la section, si elle a été choisie pour le template affiché ;
     * sinon null (le template applique sa variante par défaut).
     *
     * @param array{variant: ?string, variant_template?: ?string} $section
     */
    public static function variantFor(array $section, string $templateSlug): ?string
    {
        $chosenFor = $section['variant_template'] ?? null;

        return $chosenFor === null || $chosenFor === $templateSlug ? $section['variant'] : null;
    }

    /** Au moins un article en ligne (lien « Blog » du pied de page). */
    private function hasBlog(): bool
    {
        return $this->hasBlog ??= $this->blog->countVisible($this->clock->now()->format(DATE_ATOM)) > 0;
    }

    /**
     * @return list<array{id: int, type: string, anchor: ?string, nav_label: ?string, template: string, data: array<string, mixed>, settings: array<string, mixed>}>
     */
    private function prepareSections(int $pageId): array
    {
        $prepared = [];

        foreach ($this->pages->sectionsForDisplay($pageId, $this->preview->drafts()) as $section) {
            $type = $this->registry->get($section['type']);
            if ($type === null) {
                $this->logger->warning("Type de section inconnu « {$section['type']} » (section #{$section['id']}).");
                continue;
            }

            try {
                $data = $type->prepare($section['content']);
            } catch (Throwable $e) {
                $this->logger->error("Préparation de la section #{$section['id']} ({$section['type']}) impossible : " . $e->getMessage(), ['exception' => $e]);
                continue;
            }

            if ($data === null) {
                continue; // rien à montrer (ex. galerie vide)
            }

            $anchor = Content::anchor($section['content']);
            $navLabel = Content::text($section['content'], 'nav_label') ?: ($data['title'] ?? null);

            $prepared[] = [
                'id' => $section['id'],
                'type' => $section['type'],
                'anchor' => $anchor,
                'nav_label' => $anchor !== null && is_string($navLabel) && $navLabel !== '' ? $navLabel : null,
                'template' => $this->templates->sectionTemplate($section['type'], self::variantFor($section, $this->templates->manifest()->slug)),
                'data' => $data,
                'settings' => $section['settings'],
            ];
        }

        return $prepared;
    }

    /** @param array<string, mixed>|null $homePage */
    private function homeSections(?array $homePage = null): array
    {
        if ($this->homeSections === null) {
            $homePage ??= $this->pages->findPublishedHome()
                ?? ($this->preview->drafts() ? $this->pages->findHome() : null);
            $this->homeSections = $homePage !== null ? $this->prepareSections((int) $homePage['id']) : [];
        }

        return $this->homeSections;
    }

    /**
     * Menu principal : une entrée par section ancrée de l'accueil (Tarifs, Réalisations…).
     * Depuis une autre page, les liens pointent vers l'accueil (/#tarifs).
     *
     * @return list<array{label: string, url: string}>
     */
    private function navigation(bool $isHome): array
    {
        $items = [];
        foreach ($this->homeSections() as $section) {
            if ($section['nav_label'] !== null && $section['type'] !== 'hero') {
                $items[] = ['label' => $section['nav_label'], 'url' => ($isHome ? '' : '/') . '#' . $section['anchor']];
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $page
     * @return array{title: string, description: string, canonical: string, image: ?string, site_name: string}
     */
    /**
     * Métadonnées de la page : titre, description, adresse canonique, image et type Open Graph,
     * consigne aux robots, données structurées (JSON-LD).
     *
     * @param array<string, mixed> $page
     * @param array<string, mixed> $extra robots, json_ld, og_type, canonical_query, published_time
     * @return array<string, mixed>
     */
    private function seo(array $page, bool $isHome, string $path, array $extra = []): array
    {
        $siteName = $this->settings->string('general.site_name', (string) $this->config->get('app.name'));
        $title = trim((string) ($page['seo_title'] ?? ''));
        if ($title === '') {
            $title = $isHome
                ? $this->settings->string('seo.default_title', $siteName)
                : $page['title'] . ' — ' . $siteName;
        }

        $description = trim((string) ($page['seo_description'] ?? ''));
        if ($description === '') {
            $description = $this->settings->string('seo.default_description', $this->settings->string('general.description'));
        }

        $imageId = $page['og_media_id'] ?? $this->settings->get('seo.og_media_id');
        $image = is_numeric($imageId) ? $this->media->find((int) $imageId) : null;
        $baseUrl = $this->seoService->baseUrl();
        $query = (string) ($extra['canonical_query'] ?? '');

        return [
            'title' => $title,
            'description' => mb_substr(preg_replace('/\s+/u', ' ', $description) ?? $description, 0, 300),
            'canonical' => $baseUrl . ($path === '/' ? '/' : $path) . ($query !== '' ? '?' . $query : ''),
            'image' => $image?->isImage() ? $baseUrl . $image->url('large') : null,
            'image_alt' => $image?->isImage() ? $image->altText : null,
            'site_name' => $siteName,
            'robots' => $this->preview->active() ? 'noindex, nofollow' : $this->seoService->robotsFor($path, $extra['robots'] ?? null),
            'og_type' => (string) ($extra['og_type'] ?? 'website'),
            'published_time' => $extra['published_time'] ?? null,
            'json_ld' => array_map(SeoService::encode(...), $extra['json_ld'] ?? []),
        ];
    }
}
