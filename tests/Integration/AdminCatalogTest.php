<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Repositories\OptionRepository;

/**
 * Administration du catalogue : catégories, prestations, grille, tailles / longueurs, options.
 */
final class AdminCatalogTest extends AdminTestCase
{
    use CatalogFixtures;

    protected function database(): Database
    {
        return $this->db;
    }

    private function serviceInput(int $categoryId, array $overrides = []): array
    {
        return $overrides + [
            'category_id' => (string) $categoryId,
            'name' => 'Bantu knots',
            'slug' => '',
            'pricing_mode' => 'simple',
            'price_kind' => 'fixed',
            'price' => '60',
            'duration_minutes' => '120',
            'is_active' => '1',
        ];
    }

    public function testPagesRenderForAdminAndAreForbiddenToStaff(): void
    {
        $f = $this->matrixService();
        $admin = $this->loggedIn();

        foreach (['/admin/prestations', '/admin/prestations/nouvelle', "/admin/prestations/{$f['service']}", "/admin/prestations/{$f['service']}/grille",
                  '/admin/prestations/categories', "/admin/prestations/categories/{$f['category']}", '/admin/prestations/tailles-longueurs', '/admin/prestations/options'] as $path) {
            self::assertSame(200, $admin->get($path)->status(), $path);
        }

        self::assertSame(403, $this->loggedIn('staff', 'collab@example.test')->get('/admin/prestations')->status());
    }

    public function testCategoryCreationWithAutomaticSlugAndOptionGroups(): void
    {
        $browser = $this->loggedIn();
        $finish = (int) $this->db->fetchValue("SELECT id FROM option_groups WHERE code = 'finish'");

        $browser->post('/admin/prestations/categories', ['name' => 'Enfants (4 à 10 ans)', 'option_groups' => [(string) $finish], 'is_active' => '1']);
        $category = $this->db->fetchOne("SELECT * FROM service_categories WHERE name = 'Enfants (4 à 10 ans)'");

        self::assertSame('enfants-4-a-10-ans', $category['slug']);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT count(*) FROM option_group_assignments WHERE category_id = :c', ['c' => $category['id']]));

        $browser->post('/admin/prestations/categories', ['name' => 'Enfants 4 à 10 ans']);
        self::assertArrayHasKey('slug', $browser->errors(), 'Slug déjà utilisé.');
    }

    public function testSimpleServiceCreationAndPriceValidation(): void
    {
        $browser = $this->loggedIn();
        $category = (int) $this->db->fetchValue("INSERT INTO service_categories (slug, name) VALUES ('locs', 'Locs') RETURNING id");

        $browser->post('/admin/prestations', $this->serviceInput($category, ['price' => 'abc']));
        self::assertArrayHasKey('price', $browser->errors());

        $browser->post('/admin/prestations', $this->serviceInput($category, ['price' => '62,50', 'benefits' => "Tenue 4 semaines\n\nLéger"]));
        $service = $this->db->fetchOne("SELECT * FROM services WHERE slug = 'bantu-knots'");
        self::assertSame('62.50', $service['price']);
        self::assertSame(['Tenue 4 semaines', 'Léger'], json_decode($service['benefits'], true));

        $browser->post('/admin/prestations', $this->serviceInput($category, ['name' => 'Coco twist', 'price_kind' => 'quote', 'price' => '']));
        self::assertSame('quote', $this->db->fetchValue("SELECT price_kind FROM services WHERE slug = 'coco-twist'"));
    }

    public function testMatrixServiceGridWorkflow(): void
    {
        $browser = $this->loggedIn();
        $category = (int) $this->db->fetchValue("INSERT INTO service_categories (slug, name) VALUES ('braids', 'Braids') RETURNING id");
        $sizes = array_column($this->db->fetchAll('SELECT id, code FROM service_sizes'), 'id', 'code');
        $lengths = array_column($this->db->fetchAll('SELECT id, slug FROM service_lengths'), 'id', 'slug');

        $response = $browser->post('/admin/prestations', $this->serviceInput($category, ['name' => 'Box braids', 'pricing_mode' => 'matrix', 'price' => '']));
        $id = (int) $this->db->fetchValue("SELECT id FROM services WHERE slug = 'box-braids'");
        self::assertSame("/admin/prestations/{$id}/grille", $response->header('Location'), 'Après création, direction la grille.');

        $browser->post("/admin/prestations/{$id}/variantes", ['size_id' => (string) $sizes['S']]);
        $browser->post("/admin/prestations/{$id}/variantes", ['size_id' => (string) $sizes['M']]);
        $browser->post("/admin/prestations/{$id}/variantes", ['size_id' => (string) $sizes['S']]);
        self::assertArrayHasKey('size_id', $browser->errors(), 'Taille en double refusée.');
        $browser->post("/admin/prestations/{$id}/variantes", ['size_id' => (string) $sizes['S'], 'label' => 'S – box braids']);
        $variants = array_column($this->db->fetchAll('SELECT id, label, size_id FROM service_variants WHERE service_id = :s ORDER BY id', ['s' => $id]), 'id');
        self::assertCount(3, $variants);
        [$s, $m] = $variants;

        $variantFields = static fn (array $ids): array => array_combine($ids, array_map(static fn (int $i) => ['is_active' => '1', 'duration_minutes' => '', 'label' => $i === $ids[2] ? 'S – box braids' : '', 'note' => '', 'image_media_id' => ''], $ids));

        // Vider le libellé de « S – box braids » créerait un doublon de la taille S : refusé proprement (pas d'erreur 500).
        $browser->post("/admin/prestations/{$id}/grille", ['variants' => array_replace($variantFields($variants), [$variants[2] => ['is_active' => '1', 'label' => '']])]);
        self::assertStringContainsString('même libellé', implode(' ', array_merge(...array_values($browser->errors()))));
        $browser->get("/admin/prestations/{$id}/grille");

        // Saisie invalide : rien n'est enregistré.
        $browser->post("/admin/prestations/{$id}/grille", [
            'prices' => [$s => [$lengths['epaules'] => '80'], $m => [$lengths['epaules'] => 'abc']],
            'variants' => $variantFields($variants),
        ]);
        self::assertNotEmpty($browser->errors());
        self::assertSame(0, (int) $this->db->fetchValue('SELECT count(*) FROM variant_prices vp JOIN service_variants v ON v.id = vp.variant_id WHERE v.service_id = :s', ['s' => $id]));
        // Comme un navigateur : la redirection affiche la grille avec les erreurs, puis on corrige.
        $page = $browser->get("/admin/prestations/{$id}/grille");
        self::assertStringContainsString('Prix invalide « abc »', $page->content());
        self::assertStringContainsString('value="abc"', $page->content(), 'La saisie est conservée pour être corrigée.');

        // Saisie valide : prix, « devis », cellule vide, durée.
        $browser->post("/admin/prestations/{$id}/grille", [
            'prices' => [
                $s => [$lengths['epaules'] => '80', $lengths['classique'] => 'devis', $lengths['nuque'] => ''],
                $m => [$lengths['epaules'] => '65,5'],
            ],
            'durations' => [$s => [$lengths['epaules'] => '360']],
            'variants' => array_replace($variantFields($variants), [$m => ['is_active' => '1', 'duration_minutes' => '300', 'label' => '', 'note' => 'Avec mèches Xpression.', 'image_media_id' => '']]),
        ]);
        self::assertSame([], $browser->errors());

        $cells = $this->db->fetchAll('SELECT variant_id, length_id, price_kind, price, duration_minutes FROM variant_prices ORDER BY variant_id, length_id');
        self::assertCount(3, $cells, 'La cellule vide n\'est pas enregistrée (longueur non proposée).');
        self::assertSame('quote', $this->db->fetchValue('SELECT price_kind FROM variant_prices WHERE variant_id = :v AND length_id = :l', ['v' => $s, 'l' => $lengths['classique']]));
        self::assertSame('65.50', $this->db->fetchValue('SELECT price FROM variant_prices WHERE variant_id = :v', ['v' => $m]));
        self::assertSame(360, (int) $this->db->fetchValue('SELECT duration_minutes FROM variant_prices WHERE variant_id = :v AND length_id = :l', ['v' => $s, 'l' => $lengths['epaules']]));
        self::assertSame('Avec mèches Xpression.', $this->db->fetchValue('SELECT note FROM service_variants WHERE id = :v', ['v' => $m]));

        // Retrait d'une taille et de ses prix.
        $browser->post("/admin/prestations/{$id}/variantes/{$m}/supprimer");
        self::assertSame(0, (int) $this->db->fetchValue('SELECT count(*) FROM variant_prices WHERE variant_id = :v', ['v' => $m]));
    }

    public function testServiceRelationsImagesRelatedAndOptionOverrides(): void
    {
        $browser = $this->loggedIn();
        $f = $this->matrixService();
        $other = $this->createService('shampoing-test');
        $media = (int) $this->db->fetchValue("INSERT INTO media (disk_path, original_name, mime_type, size_bytes, width, height) VALUES ('2026/11/a.jpg', 'a.jpg', 'image/jpeg', 100, 10, 10) RETURNING id");
        $finish = (int) $this->db->fetchValue("SELECT id FROM option_groups WHERE code = 'finish'");
        $extra = (int) $this->db->fetchValue("SELECT id FROM option_groups WHERE code = 'extra'");

        $browser->post("/admin/prestations/{$f['service']}", $this->serviceInput($f['category'], [
            'name' => 'Knotless', 'slug' => 'knotless-test', 'pricing_mode' => 'matrix', 'price' => '',
            'image_ids' => [(string) $media, '999999'],
            'related_ids' => [(string) $other, (string) $f['service']],
            'option_groups' => [$finish => 'exclude', $extra => 'add'],
        ]));

        self::assertSame([], $browser->errors());
        self::assertSame([$media], array_map('intval', array_column($this->db->fetchAll('SELECT media_id FROM service_images WHERE service_id = :s', ['s' => $f['service']]), 'media_id')), 'Média inexistant ignoré.');
        self::assertSame([$other], array_map('intval', array_column($this->db->fetchAll('SELECT related_service_id FROM service_related WHERE service_id = :s', ['s' => $f['service']]), 'related_service_id')), 'Pas de lien vers elle-même.');

        $codes = array_column((new OptionRepository($this->db))->applicableGroups($f['service']), 'code');
        self::assertSame(['care', 'extra'], $codes, 'Finitions exclues, suppléments ajoutés, entretien hérité.');
    }

    public function testDeletionRules(): void
    {
        $browser = $this->loggedIn();
        $f = $this->matrixService();

        $browser->post("/admin/prestations/categories/{$f['category']}/supprimer");
        self::assertStringContainsString('contient des prestations', (string) $browser->flash('error'));

        $customer = $this->createCustomer();
        $this->db->execute(
            "INSERT INTO bookings (reference, customer_id, service_id, service_label, starts_at, ends_at, status)
             VALUES ('HSG-DEL001', :c, :s, 'Knotless', now() + interval '2 days', now() + interval '2 days 4 hours', 'confirmed')",
            ['c' => $customer, 's' => $f['service']],
        );
        $browser->post("/admin/prestations/{$f['service']}/supprimer");
        self::assertStringContainsString('désactivez-la', (string) $browser->flash('error'));
        self::assertNotNull($this->db->fetchValue('SELECT id FROM services WHERE id = :id', ['id' => $f['service']]));

        $free = $this->createService('libre-test');
        $browser->post("/admin/prestations/{$free}/supprimer");
        self::assertNull($this->db->fetchValue('SELECT id FROM services WHERE id = :id', ['id' => $free]));
    }

    public function testSizesLengthsAndReordering(): void
    {
        $browser = $this->loggedIn();
        $this->matrixService();

        $browser->post('/admin/prestations/tailles', ['code' => 'xxl', 'label' => 'Extra épaisses']);
        self::assertSame('Extra épaisses', $this->db->fetchValue("SELECT label FROM service_sizes WHERE code = 'XXL'"));
        $browser->post('/admin/prestations/tailles', ['code' => 'XXL', 'label' => 'Doublon']);
        self::assertArrayHasKey('code', $browser->errors());

        $sId = (int) $this->db->fetchValue("SELECT id FROM service_sizes WHERE code = 'S'");
        $browser->post("/admin/prestations/tailles/{$sId}/supprimer");
        self::assertStringContainsString('utilisée', (string) $browser->flash('error'), 'Taille utilisée dans une grille : conservée.');

        $browser->post('/admin/prestations/longueurs', ['length_label' => 'Genoux']);
        self::assertSame('genoux', $this->db->fetchValue("SELECT slug FROM service_lengths WHERE label = 'Genoux'"));

        // Réordonnancement JSON (glisser-déposer).
        $ids = array_reverse(array_map('intval', array_column($this->db->fetchAll('SELECT id FROM service_sizes ORDER BY position'), 'id')));
        $response = $browser->request('POST', '/admin/prestations/ordre/tailles', ['ids' => $ids, '_token' => $browser->csrfToken()], [], true);
        self::assertSame(200, $response->status());
        self::assertSame($ids, array_map('intval', array_column($this->db->fetchAll('SELECT id FROM service_sizes ORDER BY position'), 'id')));
    }

    public function testOptionsManagement(): void
    {
        $browser = $this->loggedIn();

        $browser->post('/admin/prestations/options/groupes', ['label' => 'Accessoires', 'selection' => 'multiple']);
        $group = (int) $this->db->fetchValue("SELECT id FROM option_groups WHERE label = 'Accessoires'");
        self::assertSame('accessoires', $this->db->fetchValue('SELECT code FROM option_groups WHERE id = :id', ['id' => $group]));

        $browser->post('/admin/prestations/options', ['group_id' => (string) $group, 'name' => 'Fils dorés', 'price_kind' => 'fixed', 'price' => '', 'extra_minutes' => '10', 'is_active' => '1']);
        self::assertSame('0.00', $this->db->fetchValue("SELECT price FROM service_options WHERE name = 'Fils dorés'"), 'Prix vide = offert.');

        $browser->post('/admin/prestations/options', ['group_id' => (string) $group, 'name' => 'fils dorés', 'price_kind' => 'fixed', 'price' => '5']);
        self::assertArrayHasKey('name', $browser->errors(), 'Nom en double dans le groupe.');
    }
}
