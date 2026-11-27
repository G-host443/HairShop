<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class PageRepository
{
    /** État « brouillon » effectif d'une section : la modification en attente, sinon la version publiée. */
    private const EFFECTIVE = 'id, page_id, type,
        COALESCE(draft_variant, variant) AS variant,
        CASE WHEN draft_variant IS NOT NULL THEN draft_variant_template ELSE variant_template END AS variant_template,
        COALESCE(draft_content, content)::text AS content,
        settings::text AS settings,
        COALESCE(draft_is_visible, is_visible) AS is_visible,
        COALESCE(draft_position, position) AS position,
        draft_deleted,
        content IS NULL AS is_new,
        (draft_content IS NOT NULL OR draft_variant IS NOT NULL OR draft_is_visible IS NOT NULL) AS is_modified';

    public function __construct(private readonly Database $db)
    {
    }

    // ---------- Site public ----------

    /** @return array<string, mixed>|null */
    public function findPublishedHome(): ?array
    {
        return $this->db->fetchOne("SELECT * FROM pages WHERE kind = 'home' AND status = 'published'");
    }

    /** @return array<string, mixed>|null accueil, publié ou non */
    public function findHome(): ?array
    {
        return $this->db->fetchOne("SELECT * FROM pages WHERE kind = 'home'");
    }

    /** @return array<string, mixed>|null */
    public function findPublishedBySlug(string $slug): ?array
    {
        return $this->db->fetchOne("SELECT * FROM pages WHERE slug = :slug AND status = 'published'", ['slug' => $slug]);
    }

    /**
     * Sections à afficher, dans l'ordre : version publiée, ou brouillon effectif (aperçu de l'administration).
     * Une section jamais publiée (content NULL) n'apparaît pas pour les visiteurs.
     *
     * @return list<array{id: int, type: string, variant: ?string, content: array<string, mixed>, settings: array<string, mixed>}>
     */
    public function sectionsForDisplay(int $pageId, bool $drafts = false): array
    {
        $rows = $drafts
            ? $this->db->fetchAll(
                'SELECT id, type, COALESCE(draft_variant, variant) AS variant,
                        CASE WHEN draft_variant IS NOT NULL THEN draft_variant_template ELSE variant_template END AS variant_template,
                        COALESCE(draft_content, content)::text AS content, settings::text AS settings
                 FROM page_sections
                 WHERE page_id = :page AND NOT draft_deleted AND COALESCE(draft_is_visible, is_visible)
                 ORDER BY COALESCE(draft_position, position), id',
                ['page' => $pageId],
            )
            : $this->db->fetchAll(
                'SELECT id, type, variant, variant_template, content::text AS content, settings::text AS settings
                 FROM page_sections
                 WHERE page_id = :page AND is_visible AND content IS NOT NULL
                 ORDER BY position, id',
                ['page' => $pageId],
            );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'type' => (string) $r['type'],
            'variant' => $r['variant'] !== null ? (string) $r['variant'] : null,
            'variant_template' => $r['variant_template'] !== null ? (string) $r['variant_template'] : null,
            'content' => json_decode((string) $r['content'], true) ?: [],
            'settings' => json_decode((string) $r['settings'], true) ?: [],
        ], $rows);
    }

    /** @return list<array{id: int, type: string, variant: ?string, content: array<string, mixed>, settings: array<string, mixed>}> */
    public function publishedSections(int $pageId): array
    {
        return $this->sectionsForDisplay($pageId);
    }

    /**
     * Pages publiées à afficher dans le pied de page (pages légales, FAQ…).
     *
     * @return list<array{slug: string, title: string, kind: string}>
     */
    public function footerPages(): array
    {
        return array_map(static fn (array $r): array => [
            'slug' => (string) $r['slug'],
            'title' => (string) $r['title'],
            'kind' => (string) $r['kind'],
        ], $this->db->fetchAll(
            "SELECT slug, title, kind FROM pages WHERE status = 'published' AND kind IN ('legal', 'standard') ORDER BY kind DESC, title"
        ));
    }

    // ---------- Administration : pages ----------

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->db->fetchAll(
            "SELECT p.*, (SELECT count(*) FROM page_sections s WHERE s.page_id = p.id AND NOT s.draft_deleted) AS sections_count
             FROM pages p
             ORDER BY CASE p.kind WHEN 'home' THEN 0 WHEN 'system' THEN 1 WHEN 'standard' THEN 2 ELSE 3 END, p.title"
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM pages WHERE id = :id', ['id' => $id]);
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT EXISTS (SELECT 1 FROM pages WHERE slug = :slug AND id <> COALESCE(CAST(:id AS bigint), 0))',
            ['slug' => $slug, 'id' => $exceptId],
        );
    }

    public function create(string $title, string $slug, string $kind): int
    {
        return (int) $this->db->fetchValue(
            "INSERT INTO pages (title, slug, kind, status) VALUES (:title, :slug, :kind, 'draft') RETURNING id",
            ['title' => $title, 'slug' => $slug, 'kind' => $kind],
        );
    }

    /** @param array{title: string, slug: string, seo_title: ?string, seo_description: ?string, og_media_id: ?int} $data */
    public function updateMeta(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE pages SET title = :title, slug = :slug, seo_title = :seo_title, seo_description = :seo_description, og_media_id = :og
             WHERE id = :id',
            [
                'id' => $id,
                'title' => $data['title'],
                'slug' => $data['slug'],
                'seo_title' => $data['seo_title'],
                'seo_description' => $data['seo_description'],
                'og' => $data['og_media_id'],
            ],
        );
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM pages WHERE id = :id', ['id' => $id]);
    }

    public function markChanged(int $pageId): void
    {
        $this->db->execute('UPDATE pages SET has_unpublished_changes = true WHERE id = :id', ['id' => $pageId]);
    }

    // ---------- Administration : sections (brouillon effectif) ----------

    /**
     * Toutes les sections de la page telles qu'elles seront après publication, plus celles marquées
     * pour suppression (draft_deleted) afin de pouvoir les restaurer.
     *
     * @return list<array<string, mixed>>
     */
    public function editableSections(int $pageId): array
    {
        return array_map(self::hydrate(...), $this->db->fetchAll(
            'SELECT ' . self::EFFECTIVE . ' FROM page_sections WHERE page_id = :page
             ORDER BY draft_deleted, COALESCE(draft_position, position), id',
            ['page' => $pageId],
        ));
    }

    /** @return array<string, mixed>|null */
    public function findSection(int $pageId, int $sectionId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT ' . self::EFFECTIVE . ', variant AS published_variant, variant_template AS published_variant_template, is_visible AS published_visible
             FROM page_sections WHERE id = :id AND page_id = :page',
            ['id' => $sectionId, 'page' => $pageId],
        );

        return $row !== null ? self::hydrate($row) : null;
    }

    public function nextPosition(int $pageId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COALESCE(max(COALESCE(draft_position, position)), -1) + 1 FROM page_sections WHERE page_id = :page AND NOT draft_deleted',
            ['page' => $pageId],
        );
    }

    /** @param array<string, mixed> $content */
    public function insertDraftSection(int $pageId, string $type, ?string $variant, int $position, array $content, ?string $variantTemplate = null): int
    {
        return (int) $this->db->fetchValue(
            'INSERT INTO page_sections (page_id, type, variant, variant_template, position, draft_position, draft_content)
             VALUES (:page, :type, :variant, :vt, :position, :position2, CAST(:content AS jsonb)) RETURNING id',
            [
                'page' => $pageId,
                'type' => $type,
                'variant' => $variant,
                'vt' => $variant !== null ? $variantTemplate : null,
                'position' => $position,
                'position2' => $position,
                'content' => self::json($content),
            ],
        );
    }

    /** @param array<string, mixed> $content */
    public function saveDraft(int $sectionId, array $content, ?string $draftVariant, ?string $draftVariantTemplate = null): void
    {
        $this->db->execute(
            'UPDATE page_sections SET draft_content = CAST(:content AS jsonb), draft_variant = :variant, draft_variant_template = :vt WHERE id = :id',
            ['id' => $sectionId, 'content' => self::json($content), 'variant' => $draftVariant, 'vt' => $draftVariant !== null ? $draftVariantTemplate : null],
        );
    }

    /** Variante d'une section jamais publiée (pas de brouillon séparé). */
    public function setVariant(int $sectionId, string $variant, string $template): void
    {
        $this->db->execute('UPDATE page_sections SET variant = :v, variant_template = :t WHERE id = :id', ['id' => $sectionId, 'v' => $variant, 't' => $template]);
    }

    public function setDraftVisibility(int $sectionId, ?bool $visible): void
    {
        $this->db->execute(
            'UPDATE page_sections SET draft_is_visible = CAST(:v AS boolean) WHERE id = :id',
            ['id' => $sectionId, 'v' => $visible === null ? null : ($visible ? 'true' : 'false')],
        );
    }

    public function setDraftDeleted(int $sectionId, bool $deleted): void
    {
        $this->db->execute('UPDATE page_sections SET draft_deleted = :d WHERE id = :id', ['id' => $sectionId, 'd' => $deleted]);
    }

    public function deleteSection(int $sectionId): void
    {
        $this->db->execute('DELETE FROM page_sections WHERE id = :id', ['id' => $sectionId]);
    }

    public function setDraftPosition(int $sectionId, int $position): void
    {
        $this->db->execute('UPDATE page_sections SET draft_position = :p WHERE id = :id', ['id' => $sectionId, 'p' => $position]);
    }

    /** Applique le brouillon : suppressions, contenu, variantes, visibilité, ordre. */
    public function publishSections(int $pageId): void
    {
        $this->db->execute('DELETE FROM page_sections WHERE page_id = :page AND draft_deleted', ['page' => $pageId]);
        $this->db->execute(
            'UPDATE page_sections SET
                content = COALESCE(draft_content, content),
                variant_template = CASE WHEN draft_variant IS NOT NULL THEN draft_variant_template ELSE variant_template END,
                variant = COALESCE(draft_variant, variant),
                is_visible = COALESCE(draft_is_visible, is_visible),
                position = COALESCE(draft_position, position),
                draft_content = NULL, draft_variant = NULL, draft_variant_template = NULL, draft_is_visible = NULL, draft_position = NULL
             WHERE page_id = :page',
            ['page' => $pageId],
        );
    }

    /** Abandonne le brouillon : sections jamais publiées supprimées, les autres reviennent à la version en ligne. */
    public function discardSections(int $pageId): void
    {
        $this->db->execute('DELETE FROM page_sections WHERE page_id = :page AND content IS NULL', ['page' => $pageId]);
        $this->db->execute(
            'UPDATE page_sections SET draft_content = NULL, draft_variant = NULL, draft_variant_template = NULL, draft_is_visible = NULL, draft_position = NULL, draft_deleted = false
             WHERE page_id = :page',
            ['page' => $pageId],
        );
    }

    public function markPublished(int $pageId): void
    {
        $this->db->execute(
            "UPDATE pages SET status = 'published', published_at = COALESCE(published_at, now()), has_unpublished_changes = false WHERE id = :id",
            ['id' => $pageId],
        );
    }

    public function markClean(int $pageId): void
    {
        $this->db->execute('UPDATE pages SET has_unpublished_changes = false WHERE id = :id', ['id' => $pageId]);
    }

    public function unpublish(int $pageId): void
    {
        $this->db->execute("UPDATE pages SET status = 'draft' WHERE id = :id", ['id' => $pageId]);
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['page_id'] = (int) $row['page_id'];
        $row['position'] = (int) $row['position'];
        $row['content'] = json_decode((string) $row['content'], true) ?: [];
        $row['settings'] = json_decode((string) $row['settings'], true) ?: [];
        foreach (['is_visible', 'draft_deleted', 'is_new', 'is_modified', 'published_visible'] as $flag) {
            if (array_key_exists($flag, $row)) {
                $row[$flag] = (bool) $row[$flag];
            }
        }

        return $row;
    }

    private static function json(array $content): string
    {
        return json_encode($content, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
