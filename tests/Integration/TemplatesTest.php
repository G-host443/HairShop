<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;

/**
 * Les cinq templates : rendu complet du site avec chacun, bascule sans perte, essai avant activation.
 */
final class TemplatesTest extends AdminTestCase
{
    use CatalogFixtures;

    private const TEMPLATES = [
        'afro-natural' => 'hero-split',
        'elegance-premium' => 'hero-fullscreen',
        'beauty-chic' => 'hero-centered',
        'modern-hair-studio' => 'hero-bold',
        'minimal-professional' => 'hero-minimal',
    ];

    protected function database(): Database
    {
        return $this->db;
    }

    private function activate(string $slug): void
    {
        $this->db->execute('UPDATE templates SET is_active = false WHERE is_active');
        $this->db->execute('UPDATE templates SET is_active = true WHERE slug = :s', ['s' => $slug]);
    }

    public function testEveryTemplateRendersTheWholeSite(): void
    {
        $this->matrixService();
        $heroTitle = (string) $this->db->fetchValue("SELECT content->>'title' FROM page_sections WHERE type = 'hero' LIMIT 1");

        foreach (self::TEMPLATES as $slug => $heroClass) {
            $this->activate($slug);
            $visitor = $this->browser('10.0.1.' . strlen($slug));

            $home = $visitor->get('/');
            self::assertSame(200, $home->status(), $slug);
            $html = $home->content();
            self::assertStringContainsString('data-template="' . $slug . '"', $html, $slug);
            self::assertStringContainsString('/themes/' . $slug . '/css/theme.css', $html, $slug);
            self::assertStringContainsString('/assets/css/site.css', $html, $slug);
            self::assertMatchesRegularExpression('~/cache/theme-' . $slug . '-[a-f0-9]{12}\.css~', $html, $slug);
            self::assertStringContainsString($heroClass, $html, "{$slug} : variante d'accueil par défaut du template.");
            self::assertStringContainsString(htmlspecialchars($heroTitle, ENT_QUOTES | ENT_HTML5), $html, "{$slug} : contenu inchangé.");

            foreach (['/prestations', '/prestations/knotless-test', '/reserver', '/galerie', '/avis', '/blog', '/contact', '/compte/connexion'] as $path) {
                self::assertSame(200, $visitor->get($path)->status(), "{$slug} {$path}");
            }
        }
    }

    public function testTemplateSpecificSections(): void
    {
        $this->matrixService();
        $this->db->execute("INSERT INTO reviews (author_name, rating, comment, status) VALUES ('Awa D.', 5, 'Superbe travail, je recommande.', 'approved')");

        $this->activate('minimal-professional');
        $html = $this->browser()->get('/')->content();
        self::assertStringContainsString('price-table-full', $html, 'Tarifs en tableau.');
        self::assertStringContainsString('href="/prestations/knotless-test">Détail et réservation', $html);

        $this->activate('beauty-chic');
        self::assertStringContainsString('data-carousel', $this->browser()->get('/')->content(), 'Avis en carrousel.');

        $this->activate('elegance-premium');
        self::assertStringContainsString('quote-list', $this->browser()->get('/')->content(), 'Avis en grandes citations.');
    }

    public function testSwitchingKeepsContentAndEachTemplateSettings(): void
    {
        $admin = $this->loggedIn();
        $sections = $this->db->fetchAll('SELECT id, type, variant, content::text AS c FROM page_sections ORDER BY id');

        // Réglage propre à afro-natural, publié.
        $admin->post('/admin/apparence/personnaliser', ['tokens' => ['color.accent' => '#C27C2C']]);
        $admin->post('/admin/apparence/publier');

        $admin->post('/admin/apparence/templates/modern-hair-studio/activer');
        self::assertSame('modern-hair-studio', $this->db->fetchValue('SELECT slug FROM templates WHERE is_active'));
        $css = $this->browser()->get('/')->content();
        self::assertStringContainsString('/themes/modern-hair-studio/css/theme.css', $css);

        // La personnalisation d'afro-natural ne s'applique pas au nouveau template…
        $admin->post('/admin/apparence/personnaliser', ['tokens' => ['color.accent' => '#D04F1F']]);
        $admin->post('/admin/apparence/publier');

        // … et elle est retrouvée en revenant à afro-natural.
        $admin->post('/admin/apparence/templates/afro-natural/activer');
        $settings = json_decode((string) $this->db->fetchValue(
            "SELECT ts.settings::text FROM template_settings ts JOIN templates t ON t.id = ts.template_id WHERE t.slug = 'afro-natural'"
        ), true);
        self::assertSame('#C27C2C', $settings['tokens']['color.accent']);
        self::assertSame('#D04F1F', json_decode((string) $this->db->fetchValue(
            "SELECT ts.settings::text FROM template_settings ts JOIN templates t ON t.id = ts.template_id WHERE t.slug = 'modern-hair-studio'"
        ), true)['tokens']['color.accent']);

        self::assertSame($sections, $this->db->fetchAll('SELECT id, type, variant, content::text AS c FROM page_sections ORDER BY id'), 'Aucune section modifiée par les bascules.');
        self::assertStringContainsString('hero-split', $this->browser()->get('/')->content(), 'Variante d\'origine retrouvée.');
    }

    public function testVariantsAreChosenPerTemplate(): void
    {
        $admin = $this->loggedIn();
        $home = (int) $this->db->fetchValue("SELECT id FROM pages WHERE kind = 'home'");
        $hero = (int) $this->db->fetchValue("SELECT id FROM page_sections WHERE page_id = :p AND type = 'hero'", ['p' => $home]);
        $input = $this->db->fetchOne("SELECT content->>'title' AS title, content->>'eyebrow' AS eyebrow, content->>'text' AS text FROM page_sections WHERE id = :id", ['id' => $hero]);

        $this->activate('beauty-chic');
        self::assertStringContainsString('hero-centered', $this->browser()->get('/')->content(), 'Variante par défaut de Beauty Chic.');
        self::assertStringContainsString('id="variant-centered" value="centered" checked', $admin->get("/admin/pages/{$home}/sections/{$hero}")->content());

        // Choix d'une autre variante pour Beauty Chic : brouillon jusqu'à la publication.
        $admin->post("/admin/pages/{$home}/sections/{$hero}", [
            'variant' => 'split-logo', 'title' => $input['title'], 'eyebrow' => $input['eyebrow'], 'text' => $input['text'],
            'image_media_id' => '', 'location_from_settings' => '1',
            'primary_cta' => ['label' => 'Réserver', 'target' => '#tarifs'], 'secondary_cta' => ['label' => '', 'target' => ''],
        ]);
        self::assertSame([], $admin->errors());
        self::assertStringContainsString('hero-centered', $this->browser()->get('/')->content(), 'Rien ne change avant publication.');
        $admin->post("/admin/pages/{$home}/publier");
        self::assertStringContainsString('hero-split', $this->browser()->get('/')->content());
        self::assertSame('beauty-chic', $this->db->fetchValue('SELECT variant_template FROM page_sections WHERE id = :id', ['id' => $hero]));

        // Avec Minimal Professional, le choix fait pour Beauty Chic ne s'applique pas.
        $this->activate('minimal-professional');
        self::assertStringContainsString('hero-minimal', $this->browser()->get('/')->content());
    }

    public function testTryingATemplateBeforeActivation(): void
    {
        $admin = $this->loggedIn();

        $screen = $admin->get('/admin/apparence/templates/beauty-chic/apercu');
        self::assertSame(200, $screen->status());
        self::assertStringContainsString('Activer ce template', $screen->content());

        $frame = $admin->get('/admin/apparence/templates/beauty-chic/apercu/cadre');
        self::assertStringContainsString('data-template="beauty-chic"', $frame->content());
        self::assertStringContainsString('Aperçu — modifications non publiées', $frame->content());

        self::assertSame('afro-natural', $this->db->fetchValue('SELECT slug FROM templates WHERE is_active'), 'Essayer n\'active rien.');
        self::assertStringContainsString('data-template="afro-natural"', $this->browser('10.0.0.80')->get('/')->content());

        self::assertSame(404, $admin->get('/admin/apparence/templates/inexistant/apercu')->status());
        self::assertSame(302, $this->browser('10.0.0.81')->get('/admin/apparence/templates/beauty-chic/apercu/cadre')->status(), 'Réservé à l\'administration.');
    }
}
