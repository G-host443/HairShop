<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Sections\SectionRegistry;
use App\Services\Schedule\OpeningHoursService;

/**
 * Site public : accueil rendu depuis les sections en base, pages, formulaire de contact.
 */
final class PublicSiteTest extends AdminTestCase
{
    private function setSetting(string $key, mixed $value): void
    {
        $this->db->execute(
            'UPDATE site_settings SET value = CAST(:v AS jsonb) WHERE key = :k',
            ['k' => $key, 'v' => json_encode($value, JSON_UNESCAPED_UNICODE)],
        );
    }

    private function homeId(): int
    {
        return (int) $this->db->fetchValue("SELECT id FROM pages WHERE kind = 'home'");
    }

    public function testHomeRendersSectionsFromDatabase(): void
    {
        $this->createService('knotless-test');
        $html = $this->browser()->get('/')->content();

        self::assertStringContainsString('Des tresses et coiffures protectrices', $html, 'Titre du hero (seeder).');
        self::assertStringContainsString('Prestation test', $html, 'La prestation apparaît dans les tarifs.');
        self::assertStringContainsString('50 €', $html);
        self::assertStringContainsString('<title>Hair_style by Garance — Tresses et coiffures protectrices</title>', $html);
        self::assertMatchesRegularExpression('#/cache/theme-afro-natural-[a-f0-9]{12}\.css#', $html);
        self::assertStringContainsString('/themes/afro-natural/css/theme.css', $html);
    }

    public function testEmptySectionsAreHiddenAlongWithTheirMenuEntry(): void
    {
        $html = $this->browser()->get('/')->content();

        // Aucune prestation, aucune photo, aucun avis, aucun canal de contact dans la base de test.
        self::assertStringNotContainsString('id="tarifs"', $html);
        self::assertStringNotContainsString('id="galerie"', $html);
        self::assertStringNotContainsString('href="#tarifs"', $html, 'Pas de lien de menu vers une section masquée.');
        self::assertStringNotContainsString('Choisir ma coiffure et réserver', $html, 'Le bouton vers #tarifs disparaît avec la section.');
        self::assertStringContainsString('id="infos"', $html, 'Les horaires et règles existent : infos pratiques affichées.');
    }

    public function testHiddenAndUnpublishedSectionsAreNotRendered(): void
    {
        $home = $this->homeId();
        $this->db->execute("UPDATE page_sections SET is_visible = false WHERE page_id = :p AND type = 'practical_info'", ['p' => $home]);
        $this->db->execute(
            "INSERT INTO page_sections (page_id, type, variant, position, content, draft_content)
             VALUES (:p, 'rich_text', NULL, 99, NULL, CAST(:d AS jsonb))",
            ['p' => $home, 'd' => json_encode(['title' => 'Brouillon secret', 'text' => 'Pas encore publié'])],
        );

        $html = $this->browser()->get('/')->content();

        self::assertStringNotContainsString('id="infos"', $html);
        self::assertStringNotContainsString('Brouillon secret', $html);
    }

    public function testUnknownVariantFallsBackAndUnknownTypeIsIgnored(): void
    {
        $home = $this->homeId();
        $this->db->execute(
            "INSERT INTO page_sections (page_id, type, variant, position, content) VALUES
             (:p, 'rich_text', 'variante-inexistante', 50, CAST(:c AS jsonb)),
             (:p2, 'type_inconnu', NULL, 51, '{}'::jsonb)",
            ['p' => $home, 'p2' => $home, 'c' => json_encode(['title' => 'Notre histoire', 'text' => "Premier paragraphe.\n\nSecond paragraphe."])],
        );

        $response = $this->browser()->get('/');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Notre histoire', $response->content());
        self::assertStringContainsString('<p>Second paragraphe.</p>', $response->content());
    }

    public function testSectionContentIsEscaped(): void
    {
        $home = $this->homeId();
        $this->db->execute(
            "INSERT INTO page_sections (page_id, type, position, content) VALUES (:p, 'rich_text', 60, CAST(:c AS jsonb))",
            ['p' => $home, 'c' => json_encode(['title' => '<script>alert(1)</script>', 'text' => 'Texte'])],
        );

        $html = $this->browser()->get('/')->content();

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testUnsafeButtonTargetsAreDropped(): void
    {
        $home = $this->homeId();
        $this->db->execute(
            "INSERT INTO page_sections (page_id, type, position, content) VALUES (:p, 'cta', 70, CAST(:c AS jsonb))",
            ['p' => $home, 'c' => json_encode(['title' => 'Piège', 'button' => ['label' => 'Clic', 'target' => 'javascript:alert(1)']])],
        );

        $html = $this->browser()->get('/')->content();

        self::assertStringNotContainsString('javascript:alert', $html);
        self::assertStringNotContainsString('Piège', $html, 'Section CTA sans bouton valide : masquée.');
    }

    public function testContactChannelsComeFromSettings(): void
    {
        $this->setSetting('contact.whatsapp_number', '33612345678');
        $this->setSetting('contact.whatsapp_message', 'Bonjour Garance');
        $this->setSetting('social.instagram', 'https://www.instagram.com/garance');

        $html = $this->browser()->get('/')->content();

        self::assertStringContainsString('href="https://wa.me/33612345678?text=Bonjour%20Garance"', $html);
        self::assertStringContainsString('https://www.instagram.com/garance', $html);
        self::assertStringContainsString('href="#contact"', $html, 'La section contact apparaît dans le menu.');
    }

    public function testPrivateAddressIsOnlyShownForASalon(): void
    {
        $this->setSetting('contact.public_location', 'À 5 min de la gare');
        $this->setSetting('contact.private_address', '12 rue Secrète');

        $home = $this->browser()->get('/')->content();
        self::assertStringContainsString('À 5 min de la gare', $home);
        self::assertStringNotContainsString('12 rue Secrète', $home, 'À domicile, l\'adresse reste privée.');

        $this->setSetting('contact.location_type', 'salon');
        self::assertStringContainsString('12 rue Secrète', $this->browser()->get('/')->content());
    }

    public function testOpeningHoursAreGroupedAndClosuresAnnounced(): void
    {
        $summary = (new OpeningHoursService($this->db))->summary();

        self::assertSame([
            ['days' => 'Lundi', 'hours' => '10 h – 19 h', 'closed' => false],
            ['days' => 'Mardi', 'hours' => '10 h – 15 h', 'closed' => false],
            ['days' => 'Mercredi', 'hours' => 'Fermé', 'closed' => true],
            ['days' => 'Jeudi au dimanche', 'hours' => '10 h – 19 h', 'closed' => false],
        ], $summary);

        $this->db->execute("INSERT INTO blocked_dates (starts_on, ends_on, note) VALUES (current_date + 3, current_date + 10, 'Congés')");
        $html = $this->browser()->get('/')->content();
        self::assertMatchesRegularExpression('/Fermé du [^<]+ au [^<]+ — Congés/u', $html);
    }

    public function testPagesRouting(): void
    {
        $browser = $this->browser();

        self::assertSame(200, $browser->get('/contact')->status());
        self::assertSame(301, $browser->get('/accueil')->status(), 'L\'accueil n\'a qu\'une adresse.');
        self::assertSame(404, $browser->get('/mentions-legales')->status(), 'Page légale en brouillon : invisible.');
        self::assertSame(404, $browser->get('/page-inconnue')->status());

        $this->db->execute("UPDATE pages SET status = 'published', published_at = now() WHERE slug = 'mentions-legales'");
        $page = $browser->get('/mentions-legales');
        self::assertSame(200, $page->status());
        self::assertStringContainsString('<h1 class="page-title">Mentions légales</h1>', $page->content());
        self::assertStringContainsString('href="/mentions-legales"', $browser->get('/')->content(), 'Lien dans le pied de page.');
    }

    public function testContactFormStoresMessage(): void
    {
        $browser = $this->browser();
        $browser->get('/contact');

        $response = $browser->post('/contact', [
            'name' => 'Awa',
            'email' => 'awa@example.test',
            'phone' => '06 12 34 56 78',
            'message' => 'Bonjour, je voudrais des knotless taille M.',
            'consent' => '1',
            'return_to' => '/contact',
        ]);

        self::assertSame(303, $response->status());
        self::assertSame('/contact#contact', $response->header('Location'));
        self::assertSame(1, (int) $this->db->fetchValue("SELECT count(*) FROM contact_messages WHERE email = 'awa@example.test'"));
        self::assertStringContainsString('votre message a bien été envoyé', $browser->get('/contact')->content());
    }

    public function testContactFormValidationHoneypotAndThrottle(): void
    {
        $browser = $this->browser();
        $valid = ['name' => 'Awa', 'email' => 'awa@example.test', 'message' => 'Un message assez long.', 'consent' => '1'];

        $browser->post('/contact', ['name' => '', 'email' => 'invalide', 'message' => 'court']);
        self::assertEqualsCanonicalizing(['name', 'email', 'message', 'consent'], array_keys($browser->errors()));

        // Robot : champ piège rempli → réponse normale, rien n'est enregistré.
        $browser->post('/contact', $valid + ['website' => 'http://spam.example']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT count(*) FROM contact_messages'));

        for ($i = 0; $i < 5; $i++) {
            $browser->post('/contact', $valid);
        }
        $browser->post('/contact', $valid);
        self::assertSame(5, (int) $this->db->fetchValue('SELECT count(*) FROM contact_messages'), 'Maximum 5 messages par heure et par IP.');
        self::assertArrayHasKey('message', $browser->errors());
    }

    public function testEveryRegisteredSectionTypeHasAFallbackTemplate(): void
    {
        $registry = self::$container->get(SectionRegistry::class);

        foreach (array_keys($registry->all()) as $type) {
            self::assertFileExists(dirname(__DIR__, 2) . "/resources/views/sections/{$type}/default.twig", "Gabarit de repli manquant : {$type}");
        }
    }
}
