<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Console\Commands\BlogPublishScheduledCommand;
use App\Core\Console\Output;
use App\Core\Database;
use App\Services\Blog\BlogService;
use DI\Container;
use Tests\Support\Browser;

/**
 * Blog : rédaction, nettoyage, brouillon, publication, programmation (heure figée : 1er mars 2027, 8 h).
 */
final class BlogTest extends AdminTestCase
{
    use ContentFixtures;

    private const NOW = '2027-03-01 08:00';

    protected function database(): Database
    {
        return $this->db;
    }

    private function frozen(Browser $browser, string $at = self::NOW): Browser
    {
        return $browser->configure(static fn (Container $c) => $c->get(Clock::class)->freeze($at));
    }

    private function admin(string $at = self::NOW): Browser
    {
        return $this->frozen($this->loggedIn(), $at);
    }

    /** @param array<string, mixed> $overrides */
    private function postInput(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Entretenir ses knotless',
            'slug' => '',
            'excerpt' => '',
            'content' => '<h2>Le soir</h2><p>Dormez avec un <strong>foulard en satin</strong>.</p><ul><li>Huile légère</li></ul>',
            'category_id' => '',
            'tags' => 'Entretien, Knotless',
            'cover_media_id' => '',
            'publication' => 'now',
            'publish_date' => '',
            'publish_time' => '',
            'seo_title' => '',
            'seo_description' => '',
        ];
    }

    private function visitor(string $at = self::NOW): Browser
    {
        return $this->frozen($this->browser('10.0.0.50'), $at);
    }

    public function testPublishImmediatelyWithSanitizedContentAndTags(): void
    {
        $admin = $this->admin();
        self::assertSame(200, $admin->get('/admin/blog/nouvel-article')->status());

        $admin->post('/admin/blog', $this->postInput([
            'content' => '<p>Bonjour<script>alert(1)</script> <a href="javascript:x">piège</a></p><ol><li data-list="bullet">Huile</li></ol>',
        ]));
        self::assertSame([], $admin->errors());

        $post = $this->db->fetchOne('SELECT id, slug, status, content, published_at, author_id FROM blog_posts');
        self::assertSame(['entretenir-ses-knotless', 'published'], [$post['slug'], $post['status']]);
        self::assertSame('<p>Bonjour piège</p><ul><li>Huile</li></ul>', $post['content'], 'Contenu nettoyé à l\'enregistrement.');
        self::assertNotNull($post['author_id']);
        self::assertSame(['entretien', 'knotless'], array_column($this->db->fetchAll('SELECT t.slug FROM blog_tags t JOIN blog_post_tags pt ON pt.tag_id = t.id ORDER BY t.slug'), 'slug'));

        $page = $this->visitor()->get('/blog/entretenir-ses-knotless');
        self::assertSame(200, $page->status());
        self::assertStringContainsString('<ul><li>Huile</li></ul>', $page->content());
        self::assertStringNotContainsString('alert(1)', $page->content());
        self::assertStringContainsString('href="/blog/tag/knotless"', $page->content());
        self::assertStringContainsString('1 min de lecture', $page->content());

        $list = $this->visitor()->get('/blog')->content();
        self::assertStringContainsString('Entretenir ses knotless', $list);
        self::assertStringContainsString('Bonjour piège', $list, 'Résumé tiré du texte quand le chapeau est vide.');
        self::assertStringContainsString('href="/blog">Conseils et actualités</a>', $this->visitor()->get('/')->content(), 'Lien du pied de page.');
    }

    public function testDraftsAreNeverPublicButCanBePreviewed(): void
    {
        $admin = $this->admin();
        self::assertStringNotContainsString('href="/blog"', $this->visitor()->get('/')->content(), 'Pas de lien Blog sans article en ligne.');

        $admin->post('/admin/blog', $this->postInput(['title' => 'Brouillon secret', 'content' => '', 'publication' => 'draft']));
        $id = (int) $this->db->fetchValue('SELECT id FROM blog_posts');
        self::assertNull($this->db->fetchValue('SELECT published_at FROM blog_posts'));

        self::assertSame(404, $this->visitor()->get('/blog/brouillon-secret')->status());
        self::assertStringNotContainsString('Brouillon secret', $this->visitor()->get('/blog')->content());

        $preview = $admin->get("/admin/blog/{$id}/apercu");
        self::assertSame(200, $preview->status());
        self::assertStringContainsString('Aperçu — modifications non publiées', $preview->content());
        self::assertSame(302, $this->visitor()->get("/admin/blog/{$id}/apercu")->status());

        // Publier un article vide : refusé.
        $admin->post("/admin/blog/{$id}", $this->postInput(['title' => 'Brouillon secret', 'content' => '<p><br></p>']));
        self::assertArrayHasKey('content', $admin->errors());
    }

    public function testScheduledPublication(): void
    {
        $admin = $this->admin();

        $admin->post('/admin/blog', $this->postInput(['publication' => 'schedule', 'publish_date' => '2027-02-28', 'publish_time' => '09:00']));
        self::assertArrayHasKey('publish_date', $admin->errors(), 'Date passée refusée.');
        $admin->get('/admin');

        $admin->post('/admin/blog', $this->postInput(['publication' => 'schedule', 'publish_date' => '2027-03-03', 'publish_time' => '09:00']));
        self::assertSame([], $admin->errors());
        $post = $this->db->fetchOne("SELECT id, status, published_at AT TIME ZONE 'Europe/Paris' AS at FROM blog_posts");
        self::assertSame(['scheduled', '2027-03-03 09:00:00'], [$post['status'], $post['at']]);
        self::assertStringContainsString('Programmé', $admin->get('/admin/blog')->content());

        self::assertSame(404, $this->visitor('2027-03-03 08:59')->get('/blog/entretenir-ses-knotless')->status(), 'Pas avant l\'heure.');
        self::assertSame(200, $this->visitor('2027-03-03 09:00')->get('/blog/entretenir-ses-knotless')->status(), 'En ligne à l\'heure dite, même avant le passage de la tâche planifiée.');

        // Tâche planifiée : le statut passe à « publié ».
        $container = self::$container;
        $clock = new Clock('Europe/Paris');
        $clock->freeze('2027-03-03 09:10');
        $service = $container->make(BlogService::class, ['clock' => $clock, 'db' => $this->db, 'blog' => new \App\Repositories\BlogRepository($this->db)]);
        $command = new BlogPublishScheduledCommand($service);
        $command->setOutput(new Output());
        self::assertSame(0, $command->handle([], []));
        self::assertSame('published', $this->db->fetchValue('SELECT status FROM blog_posts'));
    }

    public function testCategoriesTagsAndListingFilters(): void
    {
        $admin = $this->admin();
        $admin->post('/admin/blog/categories', ['name' => 'Entretien']);
        $admin->post('/admin/blog/categories', ['name' => 'Inspirations']);
        $admin->post('/admin/blog/categories', ['name' => 'entretien']);
        self::assertArrayHasKey('name', $admin->errors(), 'Catégorie en double.');
        $admin->get('/admin');
        $entretien = (int) $this->db->fetchValue("SELECT id FROM blog_categories WHERE slug = 'entretien'");
        $inspirations = (int) $this->db->fetchValue("SELECT id FROM blog_categories WHERE slug = 'inspirations'");

        $admin->post('/admin/blog', $this->postInput(['title' => 'Soin du cuir chevelu', 'category_id' => (string) $entretien, 'tags' => 'Soin']));
        $admin->post('/admin/blog', $this->postInput(['title' => 'Idées pour l\'été', 'category_id' => (string) $inspirations, 'tags' => 'Été, Soin']));
        $admin->post('/admin/blog', $this->postInput(['title' => 'Soin du cuir chevelu', 'tags' => '']));
        self::assertArrayHasKey('slug', $admin->errors(), 'Adresse déjà utilisée.');
        $admin->get('/admin');

        $category = $this->visitor()->get('/blog/categorie/entretien')->content();
        self::assertStringContainsString('Soin du cuir chevelu', $category);
        self::assertStringNotContainsString('Idées pour l', $category);

        $tag = $this->visitor()->get('/blog/tag/soin')->content();
        self::assertStringContainsString('Soin du cuir chevelu', $tag);
        self::assertStringContainsString('Idées pour l&#039;été', $tag);
        self::assertSame(404, $this->visitor()->get('/blog/tag/inconnu')->status());

        // Article : « À lire aussi ».
        self::assertStringContainsString('À lire aussi', $this->visitor()->get('/blog/soin-du-cuir-chevelu')->content());

        // Renommer un tag ne change pas son adresse ; supprimer une catégorie garde les articles.
        $soin = (int) $this->db->fetchValue("SELECT id FROM blog_tags WHERE slug = 'soin'");
        $admin->post("/admin/blog/tags/{$soin}", ['name' => 'Soins du cuir chevelu']);
        self::assertSame(['Soins du cuir chevelu', 'soin'], array_values($this->db->fetchOne('SELECT name, slug FROM blog_tags WHERE id = :id', ['id' => $soin])));
        $admin->post("/admin/blog/categories/{$entretien}/supprimer");
        self::assertSame(2, (int) $this->db->fetchValue('SELECT count(*) FROM blog_posts'));
        self::assertSame(200, $admin->get('/admin/blog/categories-et-tags')->status());
    }

    public function testLatestPostsSectionCoverAndMediaUsage(): void
    {
        $admin = $this->admin();
        $cover = $this->image('couverture', 'Tresses au soleil');
        $inline = $this->image('illustration');
        $admin->post('/admin/blog', $this->postInput([
            'cover_media_id' => (string) $cover,
            'content' => '<p>Texte</p><p><img src="/uploads/test/illustration-medium.webp" data-media-id="' . $inline . '" alt="Illustration"></p>',
        ]));
        self::assertSame([], $admin->errors());

        $home = (int) $this->db->fetchValue("SELECT id FROM pages WHERE kind = 'home'");
        $this->db->execute("INSERT INTO page_sections (page_id, type, variant, position, content) VALUES (:p, 'blog_latest', 'cards', 50, '{\"title\": \"Mes conseils\"}')", ['p' => $home]);
        $page = $this->visitor()->get('/')->content();
        self::assertStringContainsString('Mes conseils', $page);
        self::assertStringContainsString('couverture-medium.webp', $page);

        $usage = (new \App\Services\Media\MediaUsageFinder($this->db));
        self::assertSame(['Couverture de l\'article « Entretenir ses knotless »'], $usage->usages($cover));
        self::assertSame(['Image dans l\'article « Entretenir ses knotless »'], $usage->usages($inline));
    }

    public function testDeleteAndPermissions(): void
    {
        $admin = $this->admin();
        $admin->post('/admin/blog', $this->postInput());
        $id = (int) $this->db->fetchValue('SELECT id FROM blog_posts');
        $admin->post("/admin/blog/{$id}/supprimer");
        self::assertSame(0, (int) $this->db->fetchValue('SELECT count(*) FROM blog_posts'));

        self::assertSame(403, $this->loggedIn('staff', 'staff@example.test')->get('/admin/blog')->status());
    }
}
