<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Database;
use App\Repositories\OptionRepository;
use App\Repositories\PricingRepository;
use App\Repositories\PromotionRepository;
use App\Services\Catalog\PricingService;
use App\Services\Catalog\PromotionService;
use DI\Container;
use Tests\Support\Browser;

/**
 * Promotions : calcul, période, affichage public, réservation, administration (heure figée : 1er mars 2027, 8 h).
 */
final class PromotionTest extends AdminTestCase
{
    use CatalogFixtures;
    use ContentFixtures;

    private const NOW = '2027-03-01 08:00';

    protected function database(): Database
    {
        return $this->db;
    }

    private function pricing(string $at = self::NOW): PricingService
    {
        $clock = new Clock('Europe/Paris');
        $clock->freeze($at);

        return new PricingService($this->db, new PricingRepository($this->db), new OptionRepository($this->db), new PromotionService(new PromotionRepository($this->db), $clock));
    }

    private function frozen(Browser $browser, string $at = self::NOW): Browser
    {
        return $browser->configure(static fn (Container $c) => $c->get(Clock::class)->freeze($at));
    }

    public function testDiscountRules(): void
    {
        self::assertSame(14.0, PromotionService::discount('percent', 20, 70, 'matrix'));
        self::assertSame(70.0, PromotionService::discount('amount', 100, 70, 'matrix'), 'Jamais en dessous de 0.');
        self::assertSame(10.0, PromotionService::discount('fixed_price', 40, 50, 'simple'));
        self::assertSame(0.0, PromotionService::discount('fixed_price', 40, 50, 'matrix'), 'Prix promotionnel réservé aux prix uniques.');
        self::assertSame(0.0, PromotionService::discount('fixed_price', 60, 50, 'simple'), 'Un « prix promo » plus cher ne s\'applique pas.');
    }

    public function testQuoteAppliesBestActivePromotionToBasePriceOnly(): void
    {
        $f = $this->matrixService();
        $this->promotion([$f['service']], 'percent', '20', '2027-02-20 00:00', '2027-03-11 00:00', name: 'Printemps');
        $this->promotion([$f['service']], 'amount', '5', '2027-02-20 00:00', '2027-03-11 00:00', name: 'Petite remise');

        $quote = $this->pricing()->quote($f['service'], $f['variants']['M'], $f['lengths']['epaules'], [$this->optionId('Perles')]);
        self::assertSame('Printemps', $quote->promotion['name'], 'La remise la plus avantageuse l\'emporte.');
        self::assertSame(14.0, $quote->discount());
        self::assertSame('66.00', $quote->total(), '70 − 20 % = 56, + perles 10 € (non remisées).');

        // Sur devis : aucune remise.
        $q = $this->pricing()->quote($f['service'], $f['variants']['S'], $f['lengths']['classique']);
        self::assertNull($q->total());
        self::assertNull($q->promotionId());
    }

    public function testPeriodAndActivation(): void
    {
        $f = $this->matrixService();
        $id = $this->promotion([$f['service']], 'percent', '10', '2027-03-02 00:00', '2027-03-05 00:00');

        self::assertNull($this->pricing()->quote($f['service'], $f['variants']['M'], $f['lengths']['epaules'])->promotionId(), 'Pas encore commencée.');
        self::assertSame($id, $this->pricing('2027-03-04 23:59')->quote($f['service'], $f['variants']['M'], $f['lengths']['epaules'])->promotionId());
        self::assertNull($this->pricing('2027-03-05 00:00')->quote($f['service'], $f['variants']['M'], $f['lengths']['epaules'])->promotionId(), 'Terminée (fin exclusive).');

        $this->db->execute('UPDATE promotions SET is_active = false WHERE id = :id', ['id' => $id]);
        self::assertNull($this->pricing('2027-03-03 12:00')->quote($f['service'], $f['variants']['M'], $f['lengths']['epaules'])->promotionId(), 'Désactivée.');
    }

    public function testPublicDisplayAndExpiry(): void
    {
        $f = $this->matrixService();
        $simple = $this->simpleService();
        $this->promotion([$f['service']], 'percent', '20', '2027-02-20 00:00', '2027-03-11 00:00', name: 'Printemps');
        $this->promotion([$simple], 'fixed_price', '40', '2027-02-20 00:00', '2027-03-11 00:00', name: 'Nattes à prix doux');

        $list = $this->frozen($this->browser())->get('/prestations')->content();
        self::assertStringContainsString('à partir de 56 €', $list, 'Plus petit prix de la grille remisé.');
        self::assertStringContainsString('<s class="price-original" aria-label="au lieu de 70 €">70 €</s>', $list);
        self::assertStringContainsString('−20 %', $list);
        self::assertStringContainsString('<s class="price-original" aria-label="au lieu de 50 €">50 €</s> 40 €', $list);

        $page = $this->frozen($this->browser())->get('/prestations/knotless-test')->content();
        self::assertStringContainsString('jusqu\'au mercredi 10 mars', $page, 'Dernier jour inclus.');
        self::assertStringContainsString('"price":56,"original":70', $page, 'Le sélecteur reçoit le prix remisé et le prix d\'origine.');

        // Section « Promotions en cours » ajoutée à l'accueil.
        $home = (int) $this->db->fetchValue("SELECT id FROM pages WHERE kind = 'home'");
        $this->db->execute("INSERT INTO page_sections (page_id, type, variant, position, content) VALUES (:p, 'promotions', 'cards', 1, '{\"title\": \"Offres du moment\"}')", ['p' => $home]);
        $content = $this->frozen($this->browser())->get('/')->content();
        self::assertStringContainsString('Nattes à prix doux', $content);
        self::assertStringContainsString('Prix spécial 40 €', $content);

        $after = $this->frozen($this->browser(), '2027-03-11 09:00');
        self::assertStringNotContainsString('Nattes à prix doux', $after->get('/')->content(), 'Promotion expirée : plus affichée.');
        self::assertStringNotContainsString('price-original', $this->frozen($this->browser(), '2027-03-11 09:00')->get('/prestations')->content());
    }

    public function testBookingStoresDiscount(): void
    {
        $f = $this->matrixService();
        $promo = $this->promotion([$f['service']], 'amount', '15', '2027-02-20 00:00', '2027-03-11 00:00', name: 'Remise de bienvenue');
        $browser = $this->frozen($this->browser());

        $summary = $browser->get("/reserver?service={$f['service']}&variant={$f['variants']['M']}&length={$f['lengths']['epaules']}&c=1&date=2027-03-08&time=10:00")->content();
        self::assertStringContainsString('Remise de bienvenue : −15 €', $summary);

        $browser->post('/reserver', [
            'service' => (string) $f['service'], 'variant' => (string) $f['variants']['M'], 'length' => (string) $f['lengths']['epaules'],
            'c' => '1', 'date' => '2027-03-08', 'time' => '10:00',
            'first_name' => 'Awa', 'last_name' => 'Diallo', 'email' => 'awa@example.test', 'phone' => '0622222222', 'rules' => '1',
        ]);
        $booking = $this->db->fetchOne('SELECT estimated_total, promotion_id, discount_amount FROM bookings');
        self::assertSame(['55.00', $promo, '15.00'], [$booking['estimated_total'], (int) $booking['promotion_id'], $booking['discount_amount']]);
        self::assertStringContainsString('remise de 15 € incluse', $this->mailer->last()->text);
    }

    public function testAdministration(): void
    {
        $f = $this->matrixService();
        $simple = $this->simpleService();
        $admin = $this->frozen($this->loggedIn());

        self::assertSame(200, $admin->get('/admin/promotions/nouvelle')->status());

        $admin->post('/admin/promotions', ['name' => 'Trop', 'discount_type' => 'percent', 'value' => '150', 'first_day' => '2027-03-01', 'last_day' => '2027-02-01', 'services' => []]);
        $errors = $admin->errors();
        foreach (['value', 'last_day', 'services'] as $key) {
            self::assertArrayHasKey($key, $errors, $key);
        }
        $admin->get('/admin');

        $admin->post('/admin/promotions', ['name' => 'Prix fixe', 'discount_type' => 'fixed_price', 'value' => '40', 'first_day' => '2027-03-01', 'last_day' => '2027-03-31', 'services' => [(string) $f['service']]]);
        self::assertStringContainsString('prix unique', implode(' ', $admin->errors()['services'] ?? []));
        $admin->get('/admin');

        $admin->post('/admin/promotions', [
            'name' => 'Mars', 'description' => 'Tout le mois', 'discount_type' => 'percent', 'value' => '12,5',
            'first_day' => '2027-03-01', 'last_day' => '2027-03-31', 'is_active' => '1', 'services' => [(string) $f['service'], (string) $simple],
        ]);
        self::assertSame([], $admin->errors());
        $row = $this->db->fetchOne("SELECT id, value, starts_at AT TIME ZONE 'Europe/Paris' AS s, ends_at AT TIME ZONE 'Europe/Paris' AS e FROM promotions WHERE name = 'Mars'");
        self::assertSame(['12.50', '2027-03-01 00:00:00', '2027-04-01 00:00:00'], [$row['value'], $row['s'], $row['e']]);
        self::assertSame(2, (int) $this->db->fetchValue('SELECT count(*) FROM promotion_services WHERE promotion_id = :id', ['id' => $row['id']]));

        $list = $admin->get('/admin/promotions')->content();
        self::assertStringContainsString('En cours', $list);
        self::assertStringContainsString('−12,5 %', $list);
        self::assertStringContainsString('value="31/03/2027"', str_replace('2027-03-31', '31/03/2027', $admin->get("/admin/promotions/{$row['id']}")->content()));

        $admin->post("/admin/promotions/{$row['id']}/activation");
        self::assertFalse((bool) $this->db->fetchValue('SELECT is_active FROM promotions WHERE id = :id', ['id' => $row['id']]));

        $admin->post("/admin/promotions/{$row['id']}/supprimer");
        self::assertSame(0, (int) $this->db->fetchValue('SELECT count(*) FROM promotions'));

        self::assertSame(403, $this->loggedIn('staff', 'staff@example.test')->get('/admin/promotions')->status());
    }
}
