<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Http\Request;
use App\Core\Validation\ValidationException;
use App\Helpers\Slug;
use App\Repositories\MediaRepository;
use App\Repositories\PageRepository;
use App\Sections\Content;
use App\Sections\SectionForm;
use App\Sections\SectionRegistry;
use App\Sections\SectionType;
use App\Services\Audit\AuditLogger;
use App\Services\Theme\TemplateManager;

/**
 * Constructeur de pages. Toute modification de section reste en brouillon (rien ne change pour
 * les visiteurs) jusqu'à « Publier » ; « Annuler les modifications » revient à la version en ligne.
 */
final class PageBuilder
{
    /** Types de page que l'administratrice peut créer (l'accueil et les pages système sont fournis). */
    public const CREATABLE_KINDS = ['standard' => 'Page de contenu', 'legal' => 'Page légale (pied de page)'];

    /** Noms affichés des variantes de présentation (sinon : identifiant du template, mis en forme). */
    public const VARIANT_LABELS = [
        'split-logo' => 'Texte et image côte à côte',
        'centered' => 'Centrée',
        'chips' => 'Pastilles de longueurs',
        'grid' => 'Grille',
        'masonry' => 'Mosaïque',
        'three-columns' => 'Trois colonnes',
        'cards' => 'Cartes',
        'carousel' => 'Carrousel',
        'buttons' => 'Boutons de contact',
        'form' => 'Formulaire',
        'banner' => 'Bandeau',
        'prose' => 'Texte',
        'fullscreen' => 'Image plein écran',
        'quotes' => 'Grandes citations',
        'split-bold' => 'Titre fort et visuel décalé',
        'minimal' => 'Sobre, sans visuel',
        'table' => 'Tableau des prix',
    ];

    /** Adresses réservées par les routes du site. */
    private const RESERVED_SLUGS = ['admin', 'api', 'compte', 'reserver', 'rdv', 'prestations', 'cache', 'themes', 'uploads', 'assets', 'sante', 'health', 'galerie', 'avis', 'blog'];

    public function __construct(
        private readonly Database $db,
        private readonly PageRepository $pages,
        private readonly SectionRegistry $registry,
        private readonly SectionForm $form,
        private readonly TemplateManager $templates,
        private readonly MediaRepository $media,
        private readonly AuditLogger $audit,
    ) {
    }

    // ---------- Pages ----------

    /** @return array<string, mixed> */
    public function page(int $id): array
    {
        return $this->pages->find($id) ?? throw HttpException::notFound('Page introuvable.');
    }

    public static function slugLocked(array $page): bool
    {
        return in_array($page['kind'], ['home', 'system'], true);
    }

    public static function deletable(array $page): bool
    {
        return !self::slugLocked($page);
    }

    /** @throws ValidationException */
    public function createPage(string $title, string $slug, string $kind, ?int $userId, ?Request $request = null): int
    {
        $title = trim($title);
        $errors = [];
        if ($title === '' || mb_strlen($title) > 120) {
            $errors['title'] = 'Indiquez un titre de 120 caractères au plus.';
        }
        if (!isset(self::CREATABLE_KINDS[$kind])) {
            $errors['kind'] = 'Type de page invalide.';
        }
        $slug = $this->checkSlug($slug !== '' ? $slug : $title, null, $errors);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $id = $this->pages->create($title, $slug, $kind);
        $this->audit->log('content.page_created', $userId, $request, 'page', $id, ['slug' => $slug]);

        return $id;
    }

    /**
     * Titre, adresse et référencement : enregistrés directement (ce ne sont pas des sections).
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function updateMeta(array $page, array $input, ?int $userId, ?Request $request = null): void
    {
        $errors = [];
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 120) {
            $errors['title'] = 'Indiquez un titre de 120 caractères au plus.';
        }

        $slug = (string) $page['slug'];
        if (!self::slugLocked($page)) {
            $slug = $this->checkSlug((string) ($input['slug'] ?? '') ?: $title, (int) $page['id'], $errors);
        }

        $seoTitle = trim((string) ($input['seo_title'] ?? ''));
        $seoDescription = trim((string) ($input['seo_description'] ?? ''));
        if (mb_strlen($seoTitle) > 70) {
            $errors['seo_title'] = 'Le titre pour les moteurs de recherche ne doit pas dépasser 70 caractères.';
        }
        if (mb_strlen($seoDescription) > 300) {
            $errors['seo_description'] = 'La description ne doit pas dépasser 300 caractères.';
        }

        $og = filter_var($input['og_media_id'] ?? '', FILTER_VALIDATE_INT);
        if ($og !== false && ($this->media->find($og)?->isImage() ?? false) === false) {
            $errors['og_media_id'] = 'Image de partage introuvable.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->pages->updateMeta((int) $page['id'], [
            'title' => $title,
            'slug' => $slug,
            'seo_title' => $seoTitle !== '' ? $seoTitle : null,
            'seo_description' => $seoDescription !== '' ? $seoDescription : null,
            'og_media_id' => $og !== false ? $og : null,
        ]);
        $this->audit->log('content.page_updated', $userId, $request, 'page', (int) $page['id']);
    }

    public function deletePage(array $page, ?int $userId, ?Request $request = null): void
    {
        if (!self::deletable($page)) {
            throw HttpException::forbidden('Cette page fait partie du site et ne peut pas être supprimée.');
        }
        $this->pages->delete((int) $page['id']);
        $this->audit->log('content.page_deleted', $userId, $request, 'page', (int) $page['id'], ['slug' => $page['slug']]);
    }

    public function publish(array $page, ?int $userId, ?Request $request = null): void
    {
        $this->db->transaction(function () use ($page): void {
            $this->pages->publishSections((int) $page['id']);
            $this->pages->markPublished((int) $page['id']);
        });
        $this->audit->log('content.page_published', $userId, $request, 'page', (int) $page['id']);
    }

    public function discard(array $page, ?int $userId, ?Request $request = null): void
    {
        $this->db->transaction(function () use ($page): void {
            $this->pages->discardSections((int) $page['id']);
            $this->pages->markClean((int) $page['id']);
        });
        $this->audit->log('content.page_discarded', $userId, $request, 'page', (int) $page['id']);
    }

    public function unpublish(array $page, ?int $userId, ?Request $request = null): void
    {
        if ($page['kind'] === 'home') {
            throw HttpException::forbidden('L\'accueil ne peut pas être retiré du site.');
        }
        $this->pages->unpublish((int) $page['id']);
        $this->audit->log('content.page_unpublished', $userId, $request, 'page', (int) $page['id']);
    }

    // ---------- Sections ----------

    /** @return array<string, mixed> */
    public function section(int $pageId, int $sectionId): array
    {
        return $this->pages->findSection($pageId, $sectionId) ?? throw HttpException::notFound('Section introuvable.');
    }

    public function type(string $type): SectionType
    {
        return $this->registry->get($type) ?? throw HttpException::notFound("Type de section inconnu : {$type}.");
    }

    /** Variante affichée avec le template actif : celle choisie pour lui, sinon la variante par défaut du template. */
    public function currentVariant(array $section): ?string
    {
        return $this->templates->manifest()->variantFor((string) $section['type'], PageRenderer::variantFor($section, $this->templates->manifest()->slug));
    }

    /** @return list<string> variantes proposées par le template actif (la première par défaut) */
    public function variants(string $type): array
    {
        return $this->templates->manifest()->sections[$type] ?? [];
    }

    public function addSection(int $pageId, string $type, ?int $userId, ?Request $request = null): int
    {
        $sectionType = $this->type($type);
        $id = $this->db->transaction(function () use ($pageId, $type, $sectionType): int {
            $id = $this->pages->insertDraftSection($pageId, $type, $this->variants($type)[0] ?? null, $this->pages->nextPosition($pageId), $this->form->defaults($sectionType), $this->templates->manifest()->slug);
            $this->pages->markChanged($pageId);

            return $id;
        });
        $this->audit->log('content.section_added', $userId, $request, 'page_section', $id, ['type' => $type]);

        return $id;
    }

    /**
     * Enregistre le formulaire d'une section dans son brouillon.
     *
     * @param array<string, mixed> $section
     * @param array<string, mixed> $input
     * @return bool la section s'affichera-t-elle avec ce contenu ?
     * @throws ValidationException
     */
    public function updateSection(array $section, array $input): bool
    {
        $type = $this->type((string) $section['type']);
        $content = $this->form->validate($type, $input, $this->anchorsOfOtherSections($section));

        $slug = $this->templates->manifest()->slug;
        $variants = $this->variants((string) $section['type']);
        $variant = is_string($input['variant'] ?? null) && in_array($input['variant'], $variants, true) ? $input['variant'] : $this->currentVariant($section);
        // Même variante, pour le même template, que la version en ligne (ou section jamais publiée) : rien en attente.
        $unchanged = $variant === $section['published_variant'] && in_array($section['published_variant_template'], [null, $slug], true);
        $draftVariant = $variant !== null && !$section['is_new'] && !$unchanged ? $variant : null;

        $this->db->transaction(function () use ($section, $content, $variant, $draftVariant, $slug): void {
            $this->pages->saveDraft((int) $section['id'], $content, $draftVariant, $slug);
            if ($section['is_new'] && $variant !== null) {
                $this->pages->setVariant((int) $section['id'], $variant, $slug);
            }
            $this->pages->markChanged((int) $section['page_id']);
        });

        try {
            return $type->prepare($content) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    public function setVisibility(array $section, bool $visible): void
    {
        $this->db->transaction(function () use ($section, $visible): void {
            $this->pages->setDraftVisibility((int) $section['id'], $visible === $section['published_visible'] ? null : $visible);
            $this->pages->markChanged((int) $section['page_id']);
        });
    }

    public function duplicate(array $section): int
    {
        return $this->db->transaction(function () use ($section): int {
            $pageId = (int) $section['page_id'];
            $copy = $this->pages->insertDraftSection($pageId, (string) $section['type'], $section['variant'], 0, $section['content'], $section['variant_template'] ?? $this->templates->manifest()->slug);

            // La copie se place juste après l'originale.
            $ids = [];
            foreach ($this->pages->editableSections($pageId) as $s) {
                if ($s['draft_deleted'] || $s['id'] === $copy) {
                    continue;
                }
                $ids[] = $s['id'];
                if ($s['id'] === (int) $section['id']) {
                    $ids[] = $copy;
                }
            }
            $this->applyOrder($pageId, $ids);

            return $copy;
        });
    }

    /** Section jamais publiée : supprimée tout de suite ; sinon, supprimée à la publication (restaurable d'ici là). */
    public function removeSection(array $section): void
    {
        $this->db->transaction(function () use ($section): void {
            if ($section['is_new']) {
                $this->pages->deleteSection((int) $section['id']);
            } else {
                $this->pages->setDraftDeleted((int) $section['id'], true);
            }
            $this->pages->markChanged((int) $section['page_id']);
        });
    }

    public function restoreSection(array $section): void
    {
        $this->db->transaction(function () use ($section): void {
            $this->pages->setDraftDeleted((int) $section['id'], false);
            $this->pages->setDraftPosition((int) $section['id'], $this->pages->nextPosition((int) $section['page_id']));
            $this->pages->markChanged((int) $section['page_id']);
        });
    }

    /**
     * Nouvel ordre des sections (glisser-déposer). Les identifiants inconnus sont ignorés ;
     * les sections absentes de la liste gardent leur place relative, à la fin.
     *
     * @param list<int> $ids
     */
    public function reorder(int $pageId, array $ids): void
    {
        $this->db->transaction(function () use ($pageId, $ids): void {
            $existing = array_column(array_filter($this->pages->editableSections($pageId), static fn (array $s): bool => !$s['draft_deleted']), 'id');
            $ordered = array_values(array_unique(array_filter($ids, static fn (int $id): bool => in_array($id, $existing, true))));
            $this->applyOrder($pageId, array_merge($ordered, array_values(array_diff($existing, $ordered))));
        });
    }

    /**
     * Sections de la page pour l'écran du constructeur, avec un aperçu lisible de leur contenu.
     *
     * @return list<array<string, mixed>>
     */
    public function sectionsForEditor(int $pageId): array
    {
        $sections = [];
        foreach ($this->pages->editableSections($pageId) as $section) {
            $type = $this->registry->get((string) $section['type']);
            $section['label'] = $type?->label() ?? $section['type'];
            $section['known'] = $type !== null;
            $section['summary'] = self::summary($section['content']);
            $section['anchor'] = Content::anchor($section['content']);
            $sections[] = $section;
        }

        return $sections;
    }

    /** Types de section proposés à l'ajout. @return array<string, string> type => libellé */
    public function availableTypes(): array
    {
        $types = [];
        foreach ($this->registry->all() as $key => $type) {
            $types[$key] = $type->label();
        }
        asort($types);

        return $types;
    }

    public function fields(SectionType $type): array
    {
        return $this->form->fields($type);
    }

    // ---------- Interne ----------

    /** @param list<int> $ids */
    private function applyOrder(int $pageId, array $ids): void
    {
        foreach (array_values($ids) as $position => $id) {
            $this->pages->setDraftPosition($id, $position);
        }
        $this->pages->markChanged($pageId);
    }

    /** @return list<string> */
    private function anchorsOfOtherSections(array $section): array
    {
        $anchors = [];
        foreach ($this->pages->editableSections((int) $section['page_id']) as $other) {
            if ($other['id'] !== (int) $section['id'] && !$other['draft_deleted'] && ($anchor = Content::anchor($other['content'])) !== null) {
                $anchors[] = $anchor;
            }
        }

        return $anchors;
    }

    /** @param array<string, string> $errors */
    private function checkSlug(string $value, ?int $pageId, array &$errors): string
    {
        $slug = Slug::make($value);
        if ($slug === '' || mb_strlen($slug) > 80) {
            $errors['slug'] = 'Adresse invalide : lettres, chiffres et tirets, 80 caractères au plus.';
        } elseif (in_array($slug, self::RESERVED_SLUGS, true)) {
            $errors['slug'] = "L'adresse « /{$slug} » est réservée par le site.";
        } elseif ($this->pages->slugExists($slug, $pageId)) {
            $errors['slug'] = "L'adresse « /{$slug} » est déjà utilisée par une autre page.";
        }

        return $slug;
    }

    /** Première ligne de texte significative du contenu (titre, sinon texte). */
    private static function summary(array $content): string
    {
        foreach (['title', 'text', 'intro'] as $key) {
            $text = Content::text($content, $key);
            if ($text !== '') {
                return mb_strimwidth(preg_replace('/\s+/', ' ', $text) ?? $text, 0, 90, '…');
            }
        }

        return '';
    }
}
