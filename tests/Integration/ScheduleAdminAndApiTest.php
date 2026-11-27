<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Database;
use DI\Container;
use Tests\Support\Browser;

/**
 * Administration des horaires et API publique des disponibilités (heure figée : lundi 1er mars 2027, 8 h).
 */
final class ScheduleAdminAndApiTest extends AdminTestCase
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

    public function testWeeklyHoursAreSavedWithBreaks(): void
    {
        $browser = $this->frozen($this->loggedIn());
        self::assertSame(200, $browser->get('/admin/horaires')->status());

        $hours = array_fill(1, 7, [['opens' => '', 'closes' => '']]);
        $hours[1] = [['opens' => '14:00', 'closes' => '19:00'], ['opens' => '09:00', 'closes' => '12:30']];
        $hours[6] = [['opens' => '09:00', 'closes' => '17:00']];

        $browser->post('/admin/horaires/semaine', ['hours' => $hours]);

        self::assertSame([], $browser->errors());
        self::assertSame(
            [['1', '09:00', '12:30'], ['1', '14:00', '19:00'], ['6', '09:00', '17:00']],
            array_map(static fn (array $r): array => array_values(array_map('strval', $r)), $this->db->fetchAll("SELECT weekday, to_char(opens_at, 'HH24:MI') AS o, to_char(closes_at, 'HH24:MI') AS c FROM opening_hours ORDER BY weekday, opens_at")),
        );
    }

    public function testInvalidWeeklyHoursAreRejectedWithoutChange(): void
    {
        $browser = $this->loggedIn();
        $before = (int) $this->db->fetchValue('SELECT count(*) FROM opening_hours');

        $hours = array_fill(1, 7, []);
        $hours[1] = [['opens' => '10:00', 'closes' => '14:00'], ['opens' => '13:00', 'closes' => '18:00']];
        $hours[2] = [['opens' => '15:00', 'closes' => '10:00']];
        $hours[3] = [['opens' => '10:00', 'closes' => '']];

        $browser->post('/admin/horaires/semaine', ['hours' => $hours]);

        self::assertEqualsCanonicalizing(['hours.1', 'hours.2', 'hours.3'], array_keys($browser->errors()));
        self::assertSame($before, (int) $this->db->fetchValue('SELECT count(*) FROM opening_hours'));
    }

    public function testClosuresSpecialHoursAndBlockedSlots(): void
    {
        $browser = $this->frozen($this->loggedIn());
        $customer = $this->createCustomer();
        $service = $this->createService('rdv-test');
        $this->db->execute(
            "INSERT INTO bookings (reference, customer_id, service_id, service_label, starts_at, ends_at, status)
             VALUES ('HSG-SCH001', :c, :s, 'Test', '2027-03-15 10:00+01', '2027-03-15 12:00+01', 'confirmed')",
            ['c' => $customer, 's' => $service],
        );

        // Fermeture sur une période qui contient un rendez-vous : enregistrée, avec avertissement.
        $browser->post('/admin/horaires/fermetures', ['closure_from' => '2027-03-15', 'closure_to' => '2027-03-20', 'closure_reason' => 'holiday', 'closure_note' => 'Congés']);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT count(*) FROM blocked_dates'));
        self::assertStringContainsString('1 rendez-vous déjà pris', (string) $browser->flash('warning'));

        $browser->post('/admin/horaires/fermetures', ['closure_from' => '2027-03-20', 'closure_to' => '2027-03-18', 'closure_reason' => 'holiday']);
        self::assertArrayHasKey('closure_to', $browser->errors());
        $browser->get('/admin/horaires');

        // Horaire exceptionnel, chevauchement refusé.
        $browser->post('/admin/horaires/exceptionnels', ['special_date' => '2027-03-03', 'special_opens' => '14:00', 'special_closes' => '18:00']);
        $browser->post('/admin/horaires/exceptionnels', ['special_date' => '2027-03-03', 'special_opens' => '17:00', 'special_closes' => '19:00']);
        self::assertArrayHasKey('special_opens', $browser->errors());
        self::assertSame(1, (int) $this->db->fetchValue('SELECT count(*) FROM special_hours'));
        $browser->get('/admin/horaires');

        // Créneau bloqué, stocké en heure locale.
        $browser->post('/admin/horaires/creneaux-bloques', ['slot_date' => '2027-03-08', 'slot_from' => '15:00', 'slot_to' => '16:00', 'slot_reason' => 'Rendez-vous médical']);
        self::assertSame('2027-03-08 14:00:00+00', $this->db->fetchValue("SELECT to_char(lower(period) AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') || '+00' FROM blocked_slots"));

        // Aperçu : le mercredi exceptionnellement ouvert propose des créneaux.
        $page = $browser->get('/admin/horaires?apercu_date=2027-03-03&apercu_duree=120')->content();
        self::assertStringContainsString('5 créneaux', $page);
        self::assertStringContainsString('<li>16:00</li>', $page);
    }

    public function testStaffCannotManageSchedule(): void
    {
        self::assertSame(403, $this->loggedIn('staff', 'collab@example.test')->get('/admin/horaires')->status());
    }

    public function testApiReturnsSlotsUsingServerSideDuration(): void
    {
        $f = $this->matrixService();
        $browser = $this->frozen($this->browser());
        $perles = $this->optionId('Perles');

        // M / mi-dos : cellule de 380 min + perles 15 min = 395 min → 10:00 … 12:30 (fin ≤ 19:00 ; 12:30 + 6 h 35 = 19:05 exclu).
        $response = $browser->getJson("/api/disponibilites?service={$f['service']}&variant={$f['variants']['M']}&length={$f['lengths']['mi-dos']}&options[]={$perles}&date=2027-03-08");
        $data = json_decode($response->content(), true);

        self::assertSame(200, $response->status());
        self::assertSame(395, $data['duration_minutes']);
        self::assertSame(['10:00', '10:30', '11:00', '11:30', '12:00'], array_column($data['slots'], 'time'));
        self::assertSame('2027-03-08T10:00:00+01:00', $data['slots'][0]['starts_at']);
        self::assertSame('16:35', $data['slots'][0]['ends_at']);
    }

    public function testApiMonthAndValidation(): void
    {
        $f = $this->matrixService();
        $browser = $this->frozen($this->browser());
        $base = "/api/disponibilites?service={$f['service']}&variant={$f['variants']['M']}&length={$f['lengths']['epaules']}";

        $month = json_decode($browser->getJson($base . '&mois=2027-03')->content(), true);
        self::assertNotContains('2027-03-03', $month['dates'], 'Mercredi fermé.');
        self::assertNotContains('2027-03-01', $month['dates'], 'Délai minimum.');
        self::assertContains('2027-03-02', $month['dates']);
        self::assertSame(['first' => '2027-03-01', 'last' => '2027-04-30'], $month['window']);

        self::assertSame(422, $browser->getJson("/api/disponibilites?service={$f['service']}&variant={$f['variants']['S']}&length={$f['lengths']['mi-dos']}&date=2027-03-08")->status(), 'Longueur non proposée.');
        self::assertSame(422, $browser->getJson('/api/disponibilites?date=2027-03-08')->status());
        self::assertSame(422, $browser->getJson($base)->status(), 'Ni date ni mois.');
    }
}
