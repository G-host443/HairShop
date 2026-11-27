<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Database;
use DI\Container;
use Tests\Support\Browser;

/**
 * Parcours de réservation public et administration des rendez-vous (heure figée : lundi 1er mars 2027, 8 h).
 */
final class BookingFlowTest extends AdminTestCase
{
    use CatalogFixtures;

    protected function database(): Database
    {
        return $this->db;
    }

    private function frozen(Browser $browser): Browser
    {
        return $browser->configure(static fn (Container $c) => $c->get(Clock::class)->freeze('2027-03-01 08:00'));
    }

    /** @param array<string, mixed> $f */
    private function base(array $f): string
    {
        return "/reserver?service={$f['service']}&variant={$f['variants']['M']}&length={$f['lengths']['epaules']}&c=1";
    }

    /** @param array<string, mixed> $f @return array<string, string> */
    private function customer(array $f, string $time = '10:00', array $extra = []): array
    {
        return $extra + [
            'service' => (string) $f['service'], 'variant' => (string) $f['variants']['M'], 'length' => (string) $f['lengths']['epaules'],
            'c' => '1', 'date' => '2027-03-08', 'time' => $time,
            'first_name' => 'Awa', 'last_name' => 'Diallo', 'email' => 'awa@example.test', 'phone' => '0612345678', 'rules' => '1',
        ];
    }

    public function testFullJourneyFromServiceChoiceToManageLink(): void
    {
        $f = $this->matrixService();
        $browser = $this->frozen($this->browser());

        // 1. Prestation
        $html = $browser->get('/reserver')->content();
        self::assertStringContainsString('Quelle coiffure souhaitez-vous ?', $html);
        self::assertStringContainsString("/reserver?service={$f['service']}", $html);

        // 2. Taille / longueur / options
        $html = $browser->get("/reserver?service={$f['service']}")->content();
        self::assertStringContainsString('name="combo"', $html);
        self::assertStringContainsString("value=\"{$f['variants']['M']}-{$f['lengths']['epaules']}\"", $html);

        // 3. Date (formulaire envoyé avec la combinaison)
        $html = $browser->get("/reserver?service={$f['service']}&combo={$f['variants']['M']}-{$f['lengths']['epaules']}&c=1")->content();
        self::assertStringContainsString('Choisissez une date', $html);
        self::assertStringContainsString('date=2027-03-08', $html);
        self::assertStringNotContainsString('date=2027-03-03', $html, 'Mercredi fermé : pas de lien.');

        // 4. Créneau
        $html = $browser->get($this->base($f) . '&date=2027-03-08')->content();
        self::assertStringContainsString('À quelle heure ?', $html);
        self::assertStringContainsString('time=10%3A00', $html);

        // 5. Coordonnées
        $html = $browser->get($this->base($f) . '&date=2027-03-08&time=10:00')->content();
        self::assertStringContainsString('Vos coordonnées', $html);
        self::assertStringContainsString('Lundi 8 mars 2027 à 10 h 00', $html);

        // Envoi → confirmation
        $response = $browser->post('/reserver', $this->customer($f));
        self::assertSame('/reserver/confirmation', $response->header('Location'));
        $html = $browser->get('/reserver/confirmation')->content();
        self::assertMatchesRegularExpression('/HSG-[A-Z0-9]{6}/', $html);
        self::assertSame(1, preg_match('#href="(/rdv/[a-f0-9]{64})"#', $html, $m));

        // Lien de gestion → annulation
        $manage = $browser->get($m[1]);
        self::assertSame(200, $manage->status());
        self::assertStringContainsString('Annuler ce rendez-vous', $manage->content());
        $browser->post($m[1] . '/annuler', ['reason' => 'Imprévu']);
        self::assertStringContainsString('bien annulé', $browser->get($m[1])->content());
        self::assertSame('cancelled', $this->db->fetchValue('SELECT status FROM bookings'));
    }

    public function testTakenSlotSendsBackToSlotChoice(): void
    {
        $f = $this->matrixService();
        $first = $this->frozen($this->browser());
        $second = $this->frozen($this->browser('10.0.0.2'));

        $first->post('/reserver', $this->customer($f));
        $response = $second->post('/reserver', $this->customer($f, '10:00', ['email' => 'autre@example.test']));

        self::assertStringContainsString('date=2027-03-08', (string) $response->header('Location'));
        self::assertStringNotContainsString('time=', (string) $response->header('Location'));
        self::assertStringContainsString('vient d', $second->get((string) $response->header('Location'))->content());
        self::assertSame(1, (int) $this->db->fetchValue('SELECT count(*) FROM bookings'));
    }

    public function testValidationRulesAndHoneypot(): void
    {
        $f = $this->matrixService();
        $browser = $this->frozen($this->browser());

        $browser->post('/reserver', $this->customer($f, '10:00', ['email' => 'invalide', 'rules' => '']));
        self::assertArrayHasKey('email', $browser->errors());
        $browser->get('/reserver');

        $browser->post('/reserver', array_diff_key($this->customer($f), ['rules' => '']));
        self::assertArrayHasKey('rules', $browser->errors());

        $browser->post('/reserver', $this->customer($f, '10:00', ['website' => 'spam']));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT count(*) FROM bookings'));
    }

    public function testInvalidLinksAndClosedBooking(): void
    {
        $browser = $this->frozen($this->browser());

        self::assertSame(404, $browser->get('/rdv/' . str_repeat('a', 64))->status());
        self::assertSame(302, $browser->get('/reserver/confirmation')->status(), 'Pas de confirmation sans réservation dans la session.');

        $this->db->execute("UPDATE site_settings SET value = 'false' WHERE key = 'booking.online_enabled'");
        self::assertStringContainsString('Réservation par message', $browser->get('/reserver')->content());
    }

    public function testAdminListCalendarDetailAndActions(): void
    {
        $f = $this->matrixService();
        $this->frozen($this->browser())->post('/reserver', $this->customer($f, '10:00', ['last_name' => '=HYPERLINK("x")']));
        $id = (int) $this->db->fetchValue('SELECT id FROM bookings');
        $admin = $this->frozen($this->loggedIn());

        $list = $admin->get('/admin/reservations')->content();
        self::assertStringContainsString('Knotless taille M', $list);
        self::assertStringContainsString('1 rendez-vous', $admin->get('/admin/reservations?q=awa')->content());
        self::assertStringContainsString('0 rendez-vous', $admin->get('/admin/reservations?q=inconnue')->content());

        foreach (['semaine', 'mois', 'jour'] as $view) {
            $page = $admin->get("/admin/reservations/calendrier?vue={$view}&date=2027-03-08");
            self::assertSame(200, $page->status(), $view);
            self::assertStringContainsString('Awa', $page->content(), $view);
        }

        // Export CSV : BOM, séparateur « ; », formule neutralisée.
        $csv = $admin->get('/admin/reservations/export?periode=tous');
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv->content());
        self::assertStringContainsString('attachment;', (string) $csv->header('Content-Disposition'));
        self::assertStringContainsString("'=HYPERLINK", $csv->content(), 'Pas d\'exécution de formule dans Excel.');

        // Fiche et actions
        self::assertSame(200, $admin->get("/admin/reservations/{$id}")->status());
        $admin->post("/admin/reservations/{$id}/statut", ['status' => 'confirmed']);
        self::assertSame('confirmed', $this->db->fetchValue('SELECT status FROM bookings WHERE id = :id', ['id' => $id]));
        $admin->post("/admin/reservations/{$id}/statut", ['status' => 'pending']);
        self::assertStringContainsString('Impossible de passer', (string) $admin->flash('error'));

        $admin->post("/admin/reservations/{$id}/prix", ['final_total' => '95,50']);
        self::assertSame('95.50', $this->db->fetchValue('SELECT final_total FROM bookings WHERE id = :id', ['id' => $id]));

        $admin->post("/admin/reservations/{$id}/deplacer", ['date' => '2027-03-03', 'time' => '10:00']);
        self::assertArrayHasKey('time', $admin->errors(), 'Mercredi fermé : refusé sans forçage.');
        $admin->get("/admin/reservations/{$id}");
        $admin->post("/admin/reservations/{$id}/deplacer", ['date' => '2027-03-03', 'time' => '10:00', 'force' => '1']);
        self::assertSame('2027-03-03', $this->db->fetchValue("SELECT to_char(starts_at AT TIME ZONE 'Europe/Paris', 'YYYY-MM-DD') FROM bookings WHERE id = :id", ['id' => $id]));
    }

    public function testAdminManualBooking(): void
    {
        $f = $this->matrixService();
        $admin = $this->frozen($this->loggedIn());

        self::assertSame(200, $admin->get('/admin/reservations/nouvelle')->status());
        self::assertStringContainsString('name="combo"', $admin->get("/admin/reservations/nouvelle?service={$f['service']}")->content());

        $admin->post('/admin/reservations', [
            'service' => (string) $f['service'], 'combo' => $f['variants']['S'] . '-' . $f['lengths']['epaules'],
            'date' => '2027-03-01', 'time' => '09:00', 'first_name' => 'Fatou', 'last_name' => 'Ba', 'phone' => '0700000001',
            'source' => 'phone', 'status' => 'confirmed', 'force' => '1',
        ]);

        $booking = $this->db->fetchOne('SELECT status, source, created_by FROM bookings');
        self::assertSame(['confirmed', 'phone'], [$booking['status'], $booking['source']]);
        self::assertNotNull($booking['created_by']);
    }

    public function testStaffCanViewAndManageBookingsButNotTheCatalogue(): void
    {
        $staff = $this->loggedIn('staff', 'collab@example.test');

        self::assertSame(200, $staff->get('/admin/reservations')->status());
        self::assertSame(403, $staff->get('/admin/prestations')->status());
    }
}
