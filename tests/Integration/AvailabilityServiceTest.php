<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Repositories\ScheduleRepository;
use App\Services\Booking\AvailabilityService;
use App\Services\Settings\SettingsService;
use DateTimeImmutable;

/**
 * Moteur de disponibilités, heure figée au lundi 1er mars 2027, 8 h (Europe/Paris).
 *
 * Horaires de référence (seeder) : lundi 10-19, mardi 10-15, mercredi fermé, jeudi → dimanche 10-19.
 * Paramètres : délai minimum 12 h, horizon 60 jours, battement 15 min, créneaux toutes les 30 min.
 */
final class AvailabilityServiceTest extends DatabaseTestCase
{
    private Clock $clock;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new Clock('Europe/Paris');
        $this->clock->freeze('2027-03-01 08:00');
    }

    /** Service neuf à chaque appel : aucun cache entre deux modifications du planning. */
    private function availability(): AvailabilityService
    {
        return new AvailabilityService(new ScheduleRepository($this->db), new SettingsService($this->db), $this->clock);
    }

    /** @return list<string> heures « HH:MM » */
    private function times(string $date, int $duration, ?int $exclude = null): array
    {
        return array_map(static fn (DateTimeImmutable $d): string => $d->format('H:i'), $this->availability()->slotsForDate($date, $duration, $exclude));
    }

    private function setting(string $key, mixed $value): void
    {
        $this->db->execute('UPDATE site_settings SET value = CAST(:v AS jsonb) WHERE key = :k', ['k' => $key, 'v' => json_encode($value)]);
    }

    private function booking(string $start, string $end, string $status = 'confirmed'): int
    {
        static $customer = null;
        $customer = $this->db->fetchValue("SELECT id FROM customers WHERE email = 'dispo@example.test'") ?? $this->createCustomer('dispo@example.test');
        $service = $this->db->fetchValue("SELECT id FROM services WHERE slug = 'dispo-service'") ?? $this->createService('dispo-service');

        return (int) $this->db->fetchValue(
            "INSERT INTO bookings (reference, customer_id, service_id, service_label, starts_at, ends_at, status, cancelled_at)
             VALUES (:ref, :c, :s, 'Test', CAST(:start AS timestamptz), CAST(:end AS timestamptz), :status, CASE WHEN :st2 = 'cancelled' THEN now() END)
             RETURNING id",
            ['ref' => sprintf('HSG-AV%04d', ++$this->sequence), 'c' => $customer, 's' => $service, 'start' => $start, 'end' => $end, 'status' => $status, 'st2' => $status],
        );
    }

    public function testRegularDaySlotsFitInsideOpeningHours(): void
    {
        $times = $this->times('2027-03-08', 120); // lundi 10-19

        self::assertSame('10:00', $times[0]);
        self::assertSame('17:00', end($times), 'Dernier départ : 17:00 + 2 h = 19:00.');
        self::assertCount(15, $times);
    }

    public function testClosedDayAndTooLongService(): void
    {
        self::assertSame([], $this->times('2027-03-03', 60), 'Mercredi fermé.');
        self::assertSame([], $this->times('2027-03-02', 360), 'Mardi 10-15 : une prestation de 6 h ne tient pas.');
        self::assertSame(['10:00'], $this->times('2027-03-02', 300));
    }

    public function testMinimumNotice(): void
    {
        self::assertSame([], $this->times('2027-03-01', 60), 'Aujourd\'hui 8 h + 12 h = 20 h : plus rien aujourd\'hui.');

        $this->clock->freeze('2027-03-01 23:00'); // + 12 h = mardi 11 h
        self::assertSame('11:00', $this->times('2027-03-02', 60)[0]);

        $this->setting('booking.min_notice_hours', 0);
        $this->clock->freeze('2027-03-08 15:10');
        self::assertSame('15:30', $this->times('2027-03-08', 60)[0], 'Pas de créneau dans le passé.');
    }

    public function testHorizon(): void
    {
        self::assertNotSame([], $this->times('2027-04-30', 60), 'J+60 (vendredi) : réservable.');
        self::assertSame([], $this->times('2027-05-01', 60), 'J+61 : au-delà de l\'horizon.');
        self::assertSame([], $this->times('2027-02-26', 60), 'Passé.');
        self::assertSame([], $this->times('2027-02-30', 60), 'Date invalide.');
    }

    public function testExistingBookingWithBuffer(): void
    {
        $this->booking('2027-03-08 12:00+01', '2027-03-08 14:00+01');
        $this->booking('2027-03-08 16:00+01', '2027-03-08 18:00+01', 'cancelled');

        $times = $this->times('2027-03-08', 60);

        self::assertContains('10:30', $times, '10:30-11:30 : avant le battement de 11:45.');
        self::assertNotContains('11:00', $times, '11:00-12:00 empiète sur le battement (11:45).');
        self::assertNotContains('13:00', $times);
        self::assertNotContains('14:00', $times, 'Battement de 15 min après la fin (14:15).');
        self::assertContains('14:30', $times);
        self::assertContains('16:00', $times, 'Un rendez-vous annulé ne bloque rien.');
    }

    public function testExcludedBookingDoesNotBlockItself(): void
    {
        $id = $this->booking('2027-03-08 10:00+01', '2027-03-08 12:00+01');

        self::assertNotContains('10:00', $this->times('2027-03-08', 120));
        self::assertContains('10:00', $this->times('2027-03-08', 120, $id), 'Utile pour déplacer un rendez-vous.');
    }

    public function testBlockedSlotHasNoBuffer(): void
    {
        $this->db->execute("INSERT INTO blocked_slots (period) VALUES (tstzrange('2027-03-08 15:00+01', '2027-03-08 16:00+01', '[)'))");

        $times = $this->times('2027-03-08', 60);

        self::assertContains('14:00', $times);
        self::assertNotContains('14:30', $times);
        self::assertNotContains('15:30', $times);
        self::assertContains('16:00', $times);
    }

    public function testBlockedDatesAndSpecialHours(): void
    {
        $this->db->execute("INSERT INTO blocked_dates (starts_on, ends_on) VALUES ('2027-03-08', '2027-03-09')");
        self::assertSame([], $this->times('2027-03-08', 60));
        self::assertSame([], $this->times('2027-03-09', 60));

        // Ouverture exceptionnelle un mercredi (habituellement fermé).
        $this->db->execute("INSERT INTO special_hours (date, opens_at, closes_at) VALUES ('2027-03-10', '14:00', '18:00')");
        self::assertSame(['14:00', '14:30', '15:00', '15:30', '16:00'], $this->times('2027-03-10', 120));

        // Horaires réduits un jeudi : ils remplacent les horaires habituels.
        $this->db->execute("INSERT INTO special_hours (date, opens_at, closes_at) VALUES ('2027-03-11', '10:00', '12:00')");
        self::assertSame(['10:00', '10:30', '11:00'], $this->times('2027-03-11', 60));
    }

    public function testServiceCannotSpanALunchBreak(): void
    {
        $this->db->execute('DELETE FROM opening_hours WHERE weekday = 1');
        $this->db->execute("INSERT INTO opening_hours (weekday, opens_at, closes_at) VALUES (1, '10:00', '13:00'), (1, '14:00', '19:00')");

        self::assertSame(['14:00', '14:30', '15:00'], $this->times('2027-03-08', 240), 'Le matin (3 h) est trop court pour 4 h.');
        self::assertContains('12:00', $this->times('2027-03-08', 60));
        self::assertNotContains('12:30', $this->times('2027-03-08', 60), '12:30-13:30 chevauche la pause.');
    }

    public function testDaylightSavingDay(): void
    {
        // Dimanche 28 mars 2027 : passage à l'heure d'été à 2 h.
        $slots = $this->availability()->slotsForDate('2027-03-28', 120);

        self::assertSame('10:00', $slots[0]->format('H:i'));
        self::assertSame('+02:00', $slots[0]->format('P'), 'Heure d\'été.');
        self::assertSame('17:00', end($slots)->format('H:i'));
        self::assertCount(15, $slots, 'Même nombre de créneaux qu\'un dimanche normal.');
    }

    public function testExactSlotCheck(): void
    {
        $this->booking('2027-03-08 12:00+01', '2027-03-08 14:00+01');
        $service = $this->availability();

        self::assertTrue($service->isAvailable(new DateTimeImmutable('2027-03-08 10:00', $this->clock->zone()), 60));
        self::assertFalse($service->isAvailable(new DateTimeImmutable('2027-03-08 12:30', $this->clock->zone()), 60));
        self::assertFalse($service->isAvailable(new DateTimeImmutable('2027-03-08 10:10', $this->clock->zone()), 60), 'Hors de la grille des créneaux.');
        self::assertTrue($service->isAvailable(new DateTimeImmutable('2027-03-08 09:00 UTC'), 60), 'Fuseau différent, même instant (10:00 à Paris).');
    }

    public function testAvailableDatesOfTheMonth(): void
    {
        $this->db->execute("INSERT INTO blocked_dates (starts_on, ends_on) VALUES ('2027-03-12', '2027-03-12')");

        $dates = $this->availability()->availableDates('2027-03-01', '2027-03-14', 120);

        self::assertSame(['2027-03-02', '2027-03-04', '2027-03-05', '2027-03-06', '2027-03-07', '2027-03-08', '2027-03-09', '2027-03-11', '2027-03-13', '2027-03-14'], $dates);
    }
}
