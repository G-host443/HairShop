<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\Browser;

/**
 * Constructeur de pages : brouillon, aperçu, publication, annulation, pages, permissions.
 */
final class PageBuilderTest extends AdminTestCase
{
    private function homeId(): int
    {
        return (int) $this->db->fetchValue("SELECT id FROM pages WHERE kind = 'home'");
    }

    private function sectionId(int $pageId, string $type): int
    {
        return (int) $this->db->fetchValue('SELECT id FROM page_sections WHERE page_id = :p AND type = :t ORDER BY id LIMIT 1', ['p' => $pageId, 't' => $type]);
    }

    /** @return array<string, mixed> champs complets de la section hero */
    private function heroInput(string $title): array
    {
        return [
            'variant' => 'centered',
            'eyebrow' => 'Hair_style by Garance',
            'title' => $title,
            'text' => 'Texte',
            'image_media_id' => '',
            'location_from_settings' => '1',
            'primary_cta' => ['label' => 'Réserver', 'target' => '#tarifs'],
            'secondary_cta' => ['label' => '', 'target' => ''],
        ];
    }

    private function publicHome(): string
    {
        return $this->browser('10.0.0.50')->get('/')->content();
    }

    public function testEditsStayInDraftUntilPublished(): void
    {
        $admin = $this->loggedIn();
        $home = $this->homeId();
        $hero = $this->sectionId($home, 'hero');

        $admin->post("/admin/pages/{$home}/sections/{$hero}", $this->heroInput('Nouveau titre de bannière'));
        self::assertSame([], $admin->errors());

        self::assertStringNotContainsString('Nouveau titre de bannière', $this->publicHome(), 'Les visiteurs ne voient pas le brouillon.');
        $frame = $admin->get("/admin/pages/{$home}/apercu/cadre");
        self::assertStringContainsString('Nouveau titre de bannière', $frame->content());
        self::assertStringContainsString('Aperçu — modifications non publiées', $frame->content());
        self::assertSame('no-store, private', $frame->header('Cache-Control'));
        self::assertTrue((bool) $this->db->fetchValue('SELECT has_unpublished_changes FROM pages WHERE id = :id', ['id' => $home]));

        self::assertStringContainsString('<iframe', $admin->get("/admin/pages/{$home}/apercu")->content());

        $admin->post("/admin/pages/{$home}/publier");
        self::assertStringContainsString('Nouveau titre de bannière', $this->publicHome());
        $row = $this->db->fetchOne('SELECT variant, draft_content, draft_variant FROM page_sections WHERE id = :id', ['id' => $hero]);
        self::assertSame(['centered', null, null], [$row['variant'], $row['draft_content'], $row['draft_variant']]);
        self::assertFalse((bool) $this->db->fetchValue('SELECT has_unpublished_changes FROM pages WHERE id = :id', ['id' => $home]));
    }

    public function testStructureChangesAndDiscard(): void
    {
        $admin = $this->loggedIn();
        $home = $this->homeId();
        $before = $this->publicHome();
        $contact = $this->sectionId($home, 'contact');
        $hero = $this->sectionId($home, 'hero');

        // Ajout d'un bloc de texte, masquage, suppression, duplication, nouvel ordre : tout en brouillon.
        $admin->post("/admin/pages/{$home}/sections", ['type' => 'rich_text']);
        $new = (int) $this->db->fetchValue("SELECT id FROM page_sections WHERE page_id = :p AND type = 'rich_text'", ['p' => $home]);
        $admin->post("/admin/pages/{$home}/sections/{$new}", ['title' => 'Bloc ajouté', 'text' => 'Texte **important**', 'anchor' => '', 'nav_label' => '']);
        $admin->post("/admin/pages/{$home}/sections/{$hero}/visibilite");
        $admin->post("/admin/pages/{$home}/sections/{$contact}/supprimer");
        $admin->request('POST', "/admin/pages/{$home}/sections/ordre", ['ids' => [$new], '_token' => $admin->csrfToken()], [], true);

        self::assertSame($before, $this->publicHome(), 'Site en ligne inchangé.');
        $preview = $admin->get("/admin/pages/{$home}/apercu/cadre")->content();
        self::assertStringContainsString('<strong>important</strong>', $preview);
        self::assertStringNotContainsString('class="hero', $preview);
        self::assertNotFalse($infos = strpos($preview, 'id="infos"'));
        self::assertLessThan($infos, strpos($preview, 'Bloc ajouté'), 'Le bloc ajouté passe en premier.');

        $admin->post("/admin/pages/{$home}/annuler-modifications");
        self::assertSame(0, (int) $this->db->fetchValue("SELECT count(*) FROM page_sections WHERE type = 'rich_text' AND page_id = :p", ['p' => $home]), 'Section jamais publiée : supprimée.');
        self::assertSame(0, (int) $this->db->fetchValue('SELECT count(*) FROM page_sections WHERE page_id = :p AND (draft_deleted OR draft_position IS NOT NULL OR draft_is_visible IS NOT NULL)', ['p' => $home]));
        self::assertSame($before, $this->publicHome());
    }

    public function testDeleteRestoreDuplicateAndPublish(): void
    {
        $admin = $this->loggedIn();
        $home = $this->homeId();
        $contact = $this->sectionId($home, 'contact');
        $testimonials = $this->sectionId($home, 'testimonials');

        $admin->post("/admin/pages/{$home}/sections/{$contact}/supprimer");
        self::assertStringContainsString('Retirées à la prochaine publication', $admin->get("/admin/pages/{$home}")->content());
        $admin->post("/admin/pages/{$home}/sections/{$contact}/restaurer");
        $admin->post("/admin/pages/{$home}/sections/{$testimonials}/supprimer");

        $admin->post("/admin/pages/{$home}/sections/{$contact}/dupliquer");
        $copy = (int) $this->db->fetchValue("SELECT max(id) FROM page_sections WHERE page_id = :p AND type = 'contact'", ['p' => $home]);
        self::assertNotSame($contact, $copy);

        $admin->post("/admin/pages/{$home}/publier");
        self::assertSame(0, (int) $this->db->fetchValue('SELECT count(*) FROM page_sections WHERE id = :id', ['id' => $testimonials]));
        self::assertSame(2, (int) $this->db->fetchValue("SELECT count(*) FROM page_sections WHERE page_id = :p AND type = 'contact' AND content IS NOT NULL", ['p' => $home]));
        self::assertSame(
            (int) $this->db->fetchValue('SELECT position FROM page_sections WHERE id = :id', ['id' => $contact]) + 1,
            (int) $this->db->fetchValue('SELECT position FROM page_sections WHERE id = :id', ['id' => $copy]),
            'La copie suit l\'originale.',
        );
    }

    public function testSectionValidation(): void
    {
        $admin = $this->loggedIn();
        $home = $this->homeId();
        $hero = $this->sectionId($home, 'hero');
        $gallery = $this->sectionId($home, 'gallery');

        $admin->post("/admin/pages/{$home}/sections/{$hero}", ['primary_cta' => ['label' => 'Clic', 'target' => 'javascript:alert(1)']] + $this->heroInput('Titre'));
        self::assertArrayHasKey('primary_cta', $admin->errors());
        $admin->get('/admin');

        $admin->post("/admin/pages/{$home}/sections/{$gallery}", ['anchor' => 'tarifs', 'title' => 'Galerie', 'limit' => '500']);
        $errors = $admin->errors();
        self::assertArrayHasKey('anchor', $errors, 'Ancre déjà prise par la grille de tarifs.');
        self::assertArrayHasKey('limit', $errors);
        self::assertNull($this->db->fetchValue('SELECT draft_content FROM page_sections WHERE id = :id', ['id' => $gallery]));

        $admin->get('/admin');
        $admin->post("/admin/pages/{$home}/sections/{$hero}", $this->heroInput('') + ['title' => '']);
        self::assertStringContainsString('ne s\'affichera pas', (string) $admin->flash('warning'));

        $other = (int) $this->db->fetchValue("SELECT id FROM pages WHERE slug = 'contact'");
        self::assertSame(404, $admin->get("/admin/pages/{$other}/sections/{$hero}")->status(), 'Section d\'une autre page.');
    }

    public function testCreatePublishUnpublishAndDeletePage(): void
    {
        $admin = $this->loggedIn();

        $admin->post('/admin/pages', ['title' => 'Mes conseils', 'slug' => '', 'kind' => 'standard']);
        $page = $this->db->fetchOne("SELECT id, slug, status FROM pages WHERE title = 'Mes conseils'");
        self::assertSame(['mes-conseils', 'draft'], [$page['slug'], $page['status']]);
        self::assertSame(404, $this->browser('10.0.0.50')->get('/mes-conseils')->status());

        $admin->post("/admin/pages/{$page['id']}/sections", ['type' => 'rich_text']);
        $section = (int) $this->db->fetchValue('SELECT id FROM page_sections WHERE page_id = :p', ['p' => $page['id']]);
        $admin->post("/admin/pages/{$page['id']}/sections/{$section}", ['title' => 'Entretien', 'text' => "## Le soir\n- Foulard en satin\n- Huile légère", 'anchor' => '', 'nav_label' => '']);
        $admin->post("/admin/pages/{$page['id']}/publier");

        $public = $this->browser('10.0.0.50')->get('/mes-conseils');
        self::assertSame(200, $public->status());
        self::assertStringContainsString('<li>Foulard en satin</li>', $public->content());
        self::assertStringContainsString('href="/mes-conseils"', $this->publicHome(), 'Lien dans le pied de page.');

        $admin->post('/admin/pages', ['title' => 'Doublon', 'slug' => 'mes-conseils', 'kind' => 'standard']);
        self::assertArrayHasKey('slug', $admin->errors());
        $admin->get('/admin');
        $admin->post('/admin/pages', ['title' => 'Réservé', 'slug' => 'reserver', 'kind' => 'legal']);
        self::assertArrayHasKey('slug', $admin->errors());
        $admin->get('/admin');

        $admin->post("/admin/pages/{$page['id']}/retirer");
        self::assertSame(404, $this->browser('10.0.0.50')->get('/mes-conseils')->status());

        $admin->post("/admin/pages/{$page['id']}/supprimer");
        self::assertSame(0, (int) $this->db->fetchValue('SELECT count(*) FROM pages WHERE id = :id', ['id' => $page['id']]));

        self::assertSame(403, $admin->post('/admin/pages/' . $this->homeId() . '/supprimer')->status(), 'L\'accueil ne se supprime pas.');
        self::assertSame(403, $admin->post('/admin/pages/' . $this->homeId() . '/retirer')->status());
    }

    public function testPageMetaUpdate(): void
    {
        $admin = $this->loggedIn();
        $home = $this->homeId();

        $admin->post("/admin/pages/{$home}", ['title' => 'Accueil', 'slug' => 'autre-adresse', 'seo_title' => 'Tresses à Lyon', 'seo_description' => 'Knotless, box braids…', 'og_media_id' => '']);
        self::assertSame([], $admin->errors());
        self::assertSame('accueil', $this->db->fetchValue('SELECT slug FROM pages WHERE id = :id', ['id' => $home]), 'Adresse de l\'accueil fixe.');
        self::assertStringContainsString('<title>Tresses à Lyon</title>', $this->publicHome());

        $admin->post("/admin/pages/{$home}", ['title' => 'Accueil', 'seo_title' => str_repeat('x', 71)]);
        self::assertArrayHasKey('seo_title', $admin->errors());
    }

    public function testPermissionsAndPreviewProtection(): void
    {
        $staff = $this->loggedIn('staff', 'staff@example.test');
        $home = $this->homeId();

        self::assertSame(403, $staff->get('/admin/pages')->status());
        self::assertSame(403, $staff->get("/admin/pages/{$home}/apercu/cadre")->status());
        self::assertSame(403, $staff->get('/admin/apparence')->status());

        $visitor = $this->browser('10.0.0.60');
        self::assertSame(302, $visitor->get("/admin/pages/{$home}/apercu/cadre")->status(), 'Aperçu réservé aux comptes connectés.');
        self::assertSame(302, $visitor->get('/admin/apparence/apercu/cadre')->status());
    }

    public function testEditorScreensRender(): void
    {
        $admin = $this->loggedIn();
        $home = $this->homeId();

        $list = $admin->get('/admin/pages');
        self::assertSame(200, $list->status());
        self::assertStringContainsString('Mentions légales', $list->content());

        $edit = $admin->get("/admin/pages/{$home}")->content();
        self::assertStringContainsString('data-sortable', $edit);
        self::assertStringContainsString('Accueil (bannière principale)', $edit);

        foreach (['hero', 'pricing_grid', 'gallery', 'practical_info', 'testimonials', 'contact'] as $type) {
            $response = $admin->get("/admin/pages/{$home}/sections/" . $this->sectionId($home, $type));
            self::assertSame(200, $response->status(), $type);
        }
        self::assertStringContainsString('name="variant"', $admin->get("/admin/pages/{$home}/sections/" . $this->sectionId($home, 'hero'))->content());
    }
}
