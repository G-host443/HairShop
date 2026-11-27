<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;

final class PublicCatalogTest extends AdminTestCase
{
    use CatalogFixtures;

    protected function database(): Database
    {
        return $this->db;
    }

    /** @return array<string, mixed> données JSON du sélecteur insérées dans la page */
    private function selectorData(string $html): array
    {
        self::assertMatchesRegularExpression('#<script type="application/json" data-pricing-data>(.+?)</script>#s', $html);
        preg_match('#<script type="application/json" data-pricing-data>(.+?)</script>#s', $html, $m);

        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
    }

    public function testHomeEmbedsSelectorDataWithVariantsPricesAndOptions(): void
    {
        $this->matrixService();
        $data = $this->selectorData($this->browser()->get('/')->content());

        $service = $data['categories'][0]['services'][0];
        self::assertSame('Knotless', $service['name']);
        self::assertSame(['S', 'M'], array_column($service['variants'], 'label'));
        self::assertSame([90, null], array_column($service['variants'][0]['prices'], 'price'), 'Épaules 90 €, Classique sur devis.');
        self::assertSame(['quote'], array_values(array_unique(array_column(array_slice($service['variants'][0]['prices'], 1), 'kind'))));
        self::assertEqualsCanonicalizing(['Finition souhaitée', 'Entretien en plus'], array_column($service['option_groups'], 'label'));
        self::assertSame('online', $data['booking']['mode'], 'Réservation en ligne active par défaut.');
        self::assertSame('/reserver', $data['booking']['booking_url']);

        $this->db->execute("UPDATE site_settings SET value = 'false' WHERE key = 'booking.online_enabled'");
        $data = $this->selectorData($this->browser()->get('/')->content());
        self::assertSame('contact', $data['booking']['mode'], 'Réservation en ligne coupée, sans WhatsApp : formulaire de contact.');
    }

    public function testSelectorJsonCannotBreakOutOfItsScriptTag(): void
    {
        $this->matrixService('piege-test', '</script><script>alert(1)</script>');
        $html = $this->browser()->get('/')->content();

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertSame('</script><script>alert(1)</script>', $this->selectorData($html)['categories'][0]['services'][0]['name']);
    }

    public function testWhatsappModeWhenNumberIsSet(): void
    {
        $this->matrixService();
        $this->db->execute("UPDATE site_settings SET value = '\"33612345678\"' WHERE key = 'contact.whatsapp_number'");
        $this->db->execute("UPDATE site_settings SET value = 'false' WHERE key = 'booking.online_enabled'");

        $data = $this->selectorData($this->browser()->get('/')->content());

        self::assertSame('whatsapp', $data['booking']['mode']);
        self::assertSame('33612345678', $data['booking']['whatsapp']);
    }

    public function testServicesIndexAndDetailPages(): void
    {
        $f = $this->matrixService();
        $this->db->execute("UPDATE services SET summary = 'Tresses sans nœud', benefits = '[\"Plus léger\"]', description = :d WHERE id = :id", [
            'd' => "Premier paragraphe.\n\nSecond paragraphe.", 'id' => $f['service'],
        ]);
        $browser = $this->browser();

        $index = $browser->get('/prestations');
        self::assertSame(200, $index->status());
        self::assertStringContainsString('href="/prestations/knotless-test"', $index->content());
        self::assertStringContainsString('à partir de 70 €', $index->content());

        $detail = $browser->get('/prestations/knotless-test');
        $html = $detail->content();
        self::assertSame(200, $detail->status());
        self::assertStringContainsString('<h1 class="page-title">Knotless</h1>', $html);
        self::assertStringContainsString('Tresses sans nœud', $html);
        self::assertStringContainsString('<p>Second paragraphe.</p>', $html);
        self::assertStringContainsString('Plus léger', $html);
        self::assertStringContainsString('De 4 h à 6 h 20 selon la taille et la longueur', $html);
        self::assertStringContainsString('<caption>Tarifs selon la taille et la longueur</caption>', $html, 'Tableau lisible sans JavaScript.');
        self::assertTrue($this->selectorData($html)['single']);
        self::assertStringContainsString('href="/reserver?service=' . $f['service'] . '"', $html, 'Bouton vers le parcours de réservation.');

        $this->db->execute("UPDATE site_settings SET value = 'false' WHERE key = 'booking.online_enabled'");
        self::assertStringContainsString('/contact?message=Bonjour%2C%20je%20souhaite%20r%C3%A9server%20%3A%20Knotless.#contact', $browser->get('/prestations/knotless-test')->content());
    }

    public function testInactiveServicesAreNotPublic(): void
    {
        $f = $this->matrixService();
        $this->db->execute('UPDATE services SET is_active = false WHERE id = :id', ['id' => $f['service']]);

        self::assertSame(404, $this->browser()->get('/prestations/knotless-test')->status());
        self::assertStringNotContainsString('knotless-test', $this->browser()->get('/prestations')->content());

        $this->db->execute('UPDATE services SET is_active = true WHERE id = :id', ['id' => $f['service']]);
        $this->db->execute('UPDATE service_categories SET is_active = false WHERE id = :id', ['id' => $f['category']]);
        self::assertSame(404, $this->browser()->get('/prestations/knotless-test')->status(), 'Catégorie masquée : prestations masquées.');
    }

    public function testContactFormIsPrefilledFromSelector(): void
    {
        $html = $this->browser()->get('/contact?message=' . rawurlencode('Bonjour, je souhaite réserver : Knotless taille M <b>'))->content();

        self::assertStringContainsString('Bonjour, je souhaite réserver : Knotless taille M</textarea>', $html, 'Balises retirées, texte conservé.');
    }
}
