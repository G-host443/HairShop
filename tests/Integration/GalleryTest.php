<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;

/**
 * Galerie : administration, portfolio public filtrable, avant / après, fiches prestations.
 */
final class GalleryTest extends AdminTestCase
{
    use CatalogFixtures;
    use ContentFixtures;

    protected function database(): Database
    {
        return $this->db;
    }

    /** @param array<string, mixed> $overrides */
    private function item(array $overrides = []): int
    {
        $data = $overrides + [
            'media_id' => $this->image(), 'after_media_id' => null, 'title' => 'Réalisation', 'description' => null,
            'category_id' => null, 'service_id' => null, 'tags' => '{}', 'is_featured' => false, 'is_published' => true, 'position' => 0,
        ];

        return (int) $this->db->fetchValue(
            'INSERT INTO gallery_items (media_id, after_media_id, title, description, category_id, service_id, tags, is_featured, is_published, position)
             VALUES (:media_id, :after_media_id, :title, :description, :category_id, :service_id, CAST(:tags AS text[]), :is_featured, :is_published, :position) RETURNING id',
            $data,
        );
    }

    public function testAdminCreateValidationAndToggles(): void
    {
        $f = $this->matrixService();
        $before = $this->image('avant', 'Cheveux naturels avant la pose');
        $after = $this->image('apres');
        $admin = $this->loggedIn();

        self::assertSame(200, $admin->get('/admin/galerie/nouvelle')->status());

        $admin->post('/admin/galerie', ['media_id' => '', 'title' => '', 'after_media_id' => (string) $after, 'tags' => '<script>']);
        $errors = $admin->errors();
        foreach (['media_id', 'title', 'tags'] as $key) {
            self::assertArrayHasKey($key, $errors, $key);
        }
        $admin->get('/admin');

        $admin->post('/admin/galerie', ['media_id' => (string) $before, 'after_media_id' => (string) $before, 'title' => 'Doublon']);
        self::assertArrayHasKey('after_media_id', $admin->errors(), 'Avant et après identiques.');
        $admin->get('/admin');

        $admin->post('/admin/galerie', [
            'media_id' => (string) $before, 'after_media_id' => (string) $after, 'title' => 'Knotless mi-dos',
            'description' => 'Pose de 6 h', 'category_id' => '', 'service_id' => (string) $f['service'],
            'tags' => 'Mi-dos, perles,  mi-dos', 'is_published' => '1', 'is_featured' => '1',
        ]);
        self::assertSame([], $admin->errors());
        $row = $this->db->fetchOne("SELECT id, category_id, tags::text AS tags, is_featured FROM gallery_items WHERE title = 'Knotless mi-dos'");
        self::assertSame($f['category'], (int) $row['category_id'], 'Catégorie déduite de la prestation.');
        self::assertSame('{mi-dos,perles}', $row['tags'], 'Tags en minuscules, sans doublon.');

        self::assertStringContainsString('Knotless mi-dos', $admin->get('/admin/galerie')->content());
        $admin->post("/admin/galerie/{$row['id']}/publication");
        self::assertFalse((bool) $this->db->fetchValue('SELECT is_published FROM gallery_items WHERE id = :id', ['id' => $row['id']]));
        $admin->post("/admin/galerie/{$row['id']}/avant");
        self::assertFalse((bool) $this->db->fetchValue('SELECT is_featured FROM gallery_items WHERE id = :id', ['id' => $row['id']]));

        self::assertSame(403, $this->loggedIn('staff', 'staff@example.test')->get('/admin/galerie')->status());
    }

    public function testPublicPortfolioFilters(): void
    {
        $knotless = $this->matrixService();
        $nattes = $this->simpleService();
        $nattesCategory = (int) $this->db->fetchValue('SELECT category_id FROM services WHERE id = :id', ['id' => $nattes]);

        $this->item(['title' => 'Knotless perlées', 'category_id' => $knotless['category'], 'service_id' => $knotless['service'], 'tags' => '{perles,mi-dos}']);
        $this->item(['title' => 'Nattes enfant', 'category_id' => $nattesCategory, 'tags' => '{enfant}', 'is_featured' => true]);
        $this->item(['title' => 'Brouillon secret', 'is_published' => false]);
        $this->item(['title' => 'Avant après', 'after_media_id' => $this->image('apres')]);

        $all = $this->browser()->get('/galerie')->content();
        self::assertStringContainsString('3 réalisations', $all);
        self::assertStringNotContainsString('Brouillon secret', $all);
        self::assertLessThan(strpos($all, 'Knotless perlées'), strpos($all, 'Nattes enfant'), 'Mise en avant en premier.');
        self::assertStringContainsString('before-after-label">Avant', $all);
        self::assertStringContainsString('data-lightbox="galerie"', $all);
        self::assertStringContainsString('#perles', $all);

        $byCategory = $this->browser()->get('/galerie?categorie=knotless-test-cat')->content();
        self::assertStringContainsString('Knotless perlées', $byCategory);
        self::assertStringNotContainsString('Nattes enfant', $byCategory);

        $byTag = $this->browser()->get('/galerie?tag=enfant')->content();
        self::assertStringContainsString('1 réalisation', $byTag);
        self::assertStringContainsString('Nattes enfant', $byTag);

        $search = $this->browser()->get('/galerie?q=perl')->content();
        self::assertStringContainsString('Knotless perlées', $search);
        self::assertStringNotContainsString('Nattes enfant</span>', $search);

        self::assertStringContainsString('class="gallery-masonry"', $this->browser()->get('/galerie?vue=mosaique')->content());
        self::assertStringContainsString('Aucune réalisation', $this->browser()->get('/galerie?q=%25%27%22<x>')->content(), 'Recherche avec caractères spéciaux.');

        // Fiche prestation : réalisations liées.
        $service = $this->browser()->get('/prestations/knotless-test')->content();
        self::assertStringContainsString('Knotless perlées', $service);
        self::assertStringContainsString('/galerie?categorie=knotless-test-cat', $service);
    }

    public function testHomeSectionAndReorder(): void
    {
        $a = $this->item(['title' => 'Première', 'is_featured' => true]);
        $b = $this->item(['title' => 'Seconde', 'is_featured' => true, 'position' => 1]);

        $home = $this->browser()->get('/')->content();
        self::assertStringContainsString('Voir toutes les réalisations', $home);
        self::assertLessThan(strpos($home, 'Seconde'), strpos($home, 'Première'));

        $admin = $this->loggedIn();
        $admin->request('POST', '/admin/galerie/ordre', ['ids' => [$b, $a], '_token' => $admin->csrfToken()], [], true);
        $home = $this->browser()->get('/')->content();
        self::assertLessThan(strpos($home, 'Première'), strpos($home, 'Seconde'));
    }
}
