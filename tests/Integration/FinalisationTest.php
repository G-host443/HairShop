<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Console\Commands\CacheClearCommand;
use App\Core\Console\Commands\DemoClearCommand;
use App\Core\Console\Output;
use App\Services\Media\MediaUsageFinder;
use App\Services\Settings\SettingsService;
use Database\Seeders\CatalogDemoSeeder;
use Database\Seeders\DemoContentSeeder;

/**
 * Finalisation : contenu de démonstration et sa suppression, installation sur une base non migrée, caches.
 */
final class FinalisationTest extends AdminTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        DemoUploadsGuard::stash(); // images du site local mises de côté
    }

    public static function tearDownAfterClass(): void
    {
        DemoUploadsGuard::restore();
        parent::tearDownAfterClass();
    }

    protected function tearDown(): void
    {
        // Les images d'exemple sont écrites sur le disque : on ne laisse rien derrière le test.
        DemoUploadsGuard::clearLive();
        parent::tearDown();
    }

    private function seedDemo(): void
    {
        (new CatalogDemoSeeder($this->db))->run();
        (new DemoContentSeeder($this->db))->run();
    }

    public function testDemoContentShowcasesTheSiteWithoutFakePublicReviewsOrDiscounts(): void
    {
        $this->seedDemo();
        $this->seedDemo(); // idempotent

        self::assertSame(8, (int) $this->db->fetchValue('SELECT count(*) FROM gallery_items'));
        self::assertSame(3, (int) $this->db->fetchValue("SELECT count(*) FROM blog_posts WHERE status = 'published'"));
        self::assertSame(10, (int) $this->db->fetchValue("SELECT count(*) FROM media WHERE disk_path LIKE 'demo/%'"));
        self::assertFileExists(public_path('uploads/demo/exemple-01-medium.webp'));

        self::assertSame(0, (int) $this->db->fetchValue("SELECT count(*) FROM reviews WHERE status <> 'pending'"), 'Avis d\'exemple jamais publiés.');
        self::assertSame(0, (int) $this->db->fetchValue('SELECT count(*) FROM promotions WHERE is_active'), 'Promotion d\'exemple désactivée.');
        self::assertSame(0, (int) $this->db->fetchValue('SELECT count(*) FROM bookings') + (int) $this->db->fetchValue('SELECT count(*) FROM customers'), 'Aucun rendez-vous ni cliente fictive.');
        self::assertSame('draft', $this->db->fetchValue("SELECT status FROM pages WHERE slug = 'faq'"), 'FAQ proposée en brouillon.');

        $home = $this->browser()->get('/')->content();
        self::assertStringContainsString('Knotless mi-dos', $home);
        self::assertStringNotContainsString('Avis d\'exemple', $home);
        self::assertSame(200, $this->browser()->get('/blog/entretenir-ses-tresses-6-semaines')->status());
        self::assertStringContainsString('Exemple — Awa D.', $this->loggedIn()->get('/admin/avis')->content(), 'À modérer dans l\'administration.');
    }

    public function testDemoClearRemovesExampleContentButKeepsCatalogAndRealContent(): void
    {
        $this->seedDemo();
        $real = (int) $this->db->fetchValue("INSERT INTO reviews (author_name, rating, comment, status) VALUES ('Vraie cliente', 5, 'Un vrai avis à garder.', 'approved') RETURNING id");
        $logo = (int) $this->db->fetchValue("SELECT id FROM media WHERE disk_path = 'demo/exemple-02.jpg'");
        $this->db->execute("UPDATE site_settings SET value = CAST(:v AS jsonb) WHERE key = 'general.logo_media_id'", ['v' => json_encode($logo)]);
        $services = (int) $this->db->fetchValue('SELECT count(*) FROM services');

        $command = new DemoClearCommand($this->db, new MediaUsageFinder($this->db));
        $command->setOutput(new Output());

        self::assertSame(0, $command->handle([], ['dry-run' => true]));
        self::assertSame(8, (int) $this->db->fetchValue('SELECT count(*) FROM gallery_items'), 'Simulation : rien supprimé.');

        self::assertSame(0, $command->handle([], []));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT count(*) FROM gallery_items'));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT count(*) FROM blog_posts'));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT count(*) FROM blog_categories'));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT count(*) FROM promotions'));
        self::assertSame([$real], array_map('intval', array_column($this->db->fetchAll('SELECT id FROM reviews'), 'id')), 'Les vrais avis restent.');
        self::assertSame($services, (int) $this->db->fetchValue('SELECT count(*) FROM services'), 'Catalogue conservé.');
        self::assertSame([$logo], array_map('intval', array_column($this->db->fetchAll("SELECT id FROM media WHERE disk_path LIKE 'demo/%'"), 'id')), 'Image encore utilisée (logo) conservée.');
        self::assertFileExists(public_path('uploads/demo/exemple-02.jpg'));
        self::assertFileDoesNotExist(public_path('uploads/demo/exemple-01.jpg'));
    }

    public function testSettingsFallBackToDefaultsBeforeMigrations(): void
    {
        // Installation : la console démarre avant que les tables existent (app:check, migrate).
        $this->db->execute('ALTER TABLE site_settings RENAME TO site_settings_absente');
        $cache = storage_path('cache/settings-essai-installation.php');
        $settings = new SettingsService($this->db, $cache);

        self::assertSame('Europe/Paris', $settings->string('general.timezone', 'Europe/Paris'));
        self::assertFileDoesNotExist($cache, 'Rien n\'est mis en cache avant l\'installation.');
    }

    public function testCacheClearRemovesGeneratedCaches(): void
    {
        $settingsCache = storage_path('cache/settings-essai-cache.php');
        $themeCss = public_path('cache/theme-essai-000000000000.css');
        file_put_contents($settingsCache, '<?php return [];');
        file_put_contents($themeCss, ':root{}');

        $command = new CacheClearCommand();
        $command->setOutput(new Output());
        self::assertSame(0, $command->handle([], []));

        self::assertFileDoesNotExist($settingsCache);
        self::assertFileDoesNotExist($themeCss);
    }
}
