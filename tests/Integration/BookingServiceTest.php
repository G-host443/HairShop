<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Security\Encrypter;
use App\Core\View;
use App\Repositories\NotificationRepository;
use App\Services\Notification\NotificationRenderer;
use App\Services\Notification\NotificationService;
use Psr\Log\LoggerInterface;
use Tests\Support\ArrayMailer;
use App\Repositories\BookingRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\OptionRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ScheduleRepository;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingRequest;
use App\Services\Booking\BookingService;
use App\Services\Booking\BookingStatus;
use App\Services\Booking\SlotUnavailableException;
use App\Services\Catalog\PricingService;
use App\Services\Settings\SettingsService;
use DateTimeImmutable;

/**
 * Création et cycle de vie des réservations (heure figée : lundi 1er mars 2027, 8 h).
 */
final class BookingServiceTest extends DatabaseTestCase
{
    use CatalogFixtures;

    private Clock $clock;
    private ArrayMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new Clock('Europe/Paris');
        $this->clock->freeze('2027-03-01 08:00');
        $this->mailer = new ArrayMailer();
    }

    protected function database(): Database
    {
        return $this->db;
    }

    /** Services neufs (aucun cache de paramètres entre deux modifications). */
    private function service(): BookingService
    {
        $settings = new SettingsService($this->db);
        $container = self::$container;

        $notifications = new NotificationService(
            $this->db,
            new NotificationRepository($this->db),
            $container->make(NotificationRenderer::class, ['settings' => $settings, 'clock' => $this->clock]),
            $this->mailer,
            $settings,
            $container->get(Config::class),
            $container->get(LoggerInterface::class),
        );

        return new BookingService(
            $this->db,
            new BookingRepository($this->db),
            new CustomerRepository($this->db),
            new PricingService($this->db, new PricingRepository($this->db), new OptionRepository($this->db)),
            new AvailabilityService(new ScheduleRepository($this->db), $settings, $this->clock),
            $settings,
            $this->clock,
            $notifications,
            $container->get(Encrypter::class),
        );
    }

    private function setting(string $key, mixed $value): void
    {
        $this->db->execute('UPDATE site_settings SET value = CAST(:v AS jsonb) WHERE key = :k', ['k' => $key, 'v' => json_encode($value)]);
    }

    /** @param array<string, mixed> $f */
    private function request(array $f, string $start = '2027-03-08 10:00', array $overrides = []): BookingRequest
    {
        $args = array_merge([
            'serviceId' => $f['service'],
            'variantId' => $f['variants']['M'],
            'lengthId' => $f['lengths']['epaules'],
            'optionIds' => [],
            'startsAt' => new DateTimeImmutable($start, $this->clock->zone()),
            'firstName' => 'Awa',
            'lastName' => 'Diallo',
            'email' => 'awa@example.test',
            'phone' => '06 12 34 56 78',
            'rulesAccepted' => true,
        ], $overrides);

        return new BookingRequest(...$args);
    }

    public function testCreatesPendingBookingWithSnapshotAndToken(): void
    {
        $f = $this->matrixService();

        $result = $this->service()->create($this->request($f, '2027-03-08 10:00', ['optionIds' => [$this->optionId('Perles')]]));
        $b = $result->booking;

        self::assertMatchesRegularExpression('/^HSG-[A-HJ-NP-Z2-9]{6}$/', $b['reference']);
        self::assertSame(BookingStatus::PENDING, $b['status'], 'Confirmation manuelle par défaut.');
        self::assertSame('Knotless taille M — Épaules', $b['service_label']);
        self::assertSame('80.00', $b['estimated_total'], '70 € + perles 10 €.');
        self::assertSame(hash('sha256', $result->manageToken), $b['manage_token_hash'], 'Seule l\'empreinte du jeton est stockée.');
        self::assertSame('2027-03-08 15:15:00', (new DateTimeImmutable($b['ends_at']))->setTimezone($this->clock->zone())->format('Y-m-d H:i:s'), '300 min + 15 min de perles.');
        self::assertSame([['label' => 'Perles', 'price' => '10.00']], array_map(static fn ($o) => ['label' => $o['label'], 'price' => $o['price']], (new BookingRepository($this->db))->options((int) $b['id'])));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT count(*) FROM booking_status_history WHERE booking_id = :id', ['id' => $b['id']]));
    }

    public function testAutoConfirmationExceptForQuotes(): void
    {
        $f = $this->matrixService();
        $this->setting('booking.auto_confirm', true);

        self::assertSame(BookingStatus::CONFIRMED, $this->service()->create($this->request($f))->booking['status']);

        $quote = $this->service()->create($this->request($f, '2027-03-11 10:00', ['variantId' => $f['variants']['S'], 'lengthId' => $f['lengths']['classique']]))->booking;
        self::assertSame(BookingStatus::PENDING, $quote['status'], 'Un prix sur devis attend toujours une validation.');
        self::assertNull($quote['estimated_total']);
    }

    public function testRulesMustBeAcceptedOnline(): void
    {
        $f = $this->matrixService();

        try {
            $this->service()->create($this->request($f, '2027-03-08 10:00', ['rulesAccepted' => false]));
            self::fail('Règles non acceptées : réservation acceptée.');
        } catch (BookingException $e) {
            self::assertSame('rules', $e->field);
        }

        $this->setting('booking.require_rules_acceptance', false);
        self::assertNotNull($this->service()->create($this->request($f, '2027-03-08 10:00', ['rulesAccepted' => false]))->booking['id']);
    }

    public function testUnavailableSlotsAreRefused(): void
    {
        $f = $this->matrixService();
        $service = $this->service();

        $service->create($this->request($f, '2027-03-08 10:00'));

        $refused = 0;
        foreach (['2027-03-08 12:00', '2027-03-03 10:00', '2027-03-08 18:00', '2027-03-01 14:00'] as $start) {
            try {
                $service->create($this->request($f, $start));
                self::fail("Créneau {$start} accepté à tort.");
            } catch (SlotUnavailableException) {
                $refused++; // chevauchement, jour fermé, dépassement de la fermeture, délai minimum
            }
        }
        self::assertSame(4, $refused);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT count(*) FROM bookings'));
    }

    public function testDatabaseConstraintIsTheLastSafetyNet(): void
    {
        $f = $this->matrixService();
        $service = $this->service();
        $service->create($this->request($f, '2027-03-08 10:00'));

        // Même en contournant la vérification applicative, PostgreSQL refuse le chevauchement…
        try {
            $service->create($this->request($f, '2027-03-08 11:00', ['ignoreSchedule' => true, 'source' => 'admin']));
            self::fail('Chevauchement accepté.');
        } catch (SlotUnavailableException) {
        }

        // … et la transaction englobante reste utilisable grâce au point de sauvegarde.
        self::assertSame(1, (int) $this->db->fetchValue('SELECT count(*) FROM bookings'));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT count(*) FROM customers'), 'La cliente créée dans la tentative échouée a été annulée avec elle.');
    }

    public function testExistingCustomerIsRecognised(): void
    {
        $f = $this->matrixService();
        $service = $this->service();

        $service->create($this->request($f, '2027-03-08 10:00'));
        $service->create($this->request($f, '2027-03-11 10:00', ['email' => 'AWA@example.test', 'phone' => null]));
        $service->create($this->request($f, '2027-03-13 10:00', ['email' => null, 'phone' => '+33612345678']));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT count(*) FROM customers'), 'Même cliente par e-mail (casse ignorée) puis par téléphone.');

        $service->create($this->request($f, '2027-03-14 10:00', ['email' => 'autre@example.test', 'phone' => '0700000000']));
        self::assertSame(2, (int) $this->db->fetchValue('SELECT count(*) FROM customers'));
    }

    public function testInvalidConfigurationIsReportedOnTheServiceField(): void
    {
        $f = $this->matrixService();

        $this->expectException(BookingException::class);
        $this->service()->create($this->request($f, '2027-03-08 10:00', ['lengthId' => $f['lengths']['mi-dos'], 'variantId' => $f['variants']['S']]));
    }

    public function testStatusTransitionsAndSlotRelease(): void
    {
        $f = $this->matrixService();
        $service = $this->service();
        $id = (int) $service->create($this->request($f))->booking['id'];

        $service->changeStatus($id, BookingStatus::CONFIRMED, null);
        try {
            $service->changeStatus($id, BookingStatus::PENDING, null);
            self::fail('Retour en attente accepté.');
        } catch (BookingException) {
        }

        $service->changeStatus($id, BookingStatus::CANCELLED, null, 'Empêchement');
        $row = $this->db->fetchOne('SELECT status, cancel_reason, cancelled_at FROM bookings WHERE id = :id', ['id' => $id]);
        self::assertSame(['cancelled', 'Empêchement'], [$row['status'], $row['cancel_reason']]);
        self::assertNotNull($row['cancelled_at']);

        // Le créneau est libéré.
        self::assertNotNull($service->create($this->request($f, '2027-03-08 10:00', ['email' => 'b@example.test']))->booking['id']);
    }

    public function testReschedule(): void
    {
        $f = $this->matrixService();
        $service = $this->service();
        $a = (int) $service->create($this->request($f, '2027-03-08 10:00'))->booking['id'];
        $service->create($this->request($f, '2027-03-11 10:00', ['email' => 'b@example.test']));

        // Décalage de 30 min qui chevauche son propre ancien créneau : autorisé.
        $service->reschedule($a, new DateTimeImmutable('2027-03-08 10:30', $this->clock->zone()), null);
        self::assertSame('10:30', (new DateTimeImmutable((string) $this->db->fetchValue('SELECT starts_at FROM bookings WHERE id = :id', ['id' => $a])))->setTimezone($this->clock->zone())->format('H:i'));

        $this->expectException(SlotUnavailableException::class);
        $service->reschedule($a, new DateTimeImmutable('2027-03-11 11:00', $this->clock->zone()), null);
    }

    public function testCustomerCancellationDeadline(): void
    {
        $f = $this->matrixService();
        $service = $this->service();
        $booking = $service->create($this->request($f, '2027-03-08 10:00'))->booking;

        self::assertTrue($service->canCustomerCancel($booking));

        $this->clock->freeze('2027-03-06 11:00'); // moins de 48 h avant
        self::assertFalse($service->canCustomerCancel($booking));
        try {
            $service->cancelByCustomer($booking, null);
            self::fail('Annulation hors délai acceptée.');
        } catch (BookingException) {
        }

        $this->clock->freeze('2027-03-06 09:00'); // 49 h avant
        $service->cancelByCustomer($booking, 'Imprévu');
        self::assertSame('cancelled', $this->db->fetchValue('SELECT status FROM bookings WHERE id = :id', ['id' => $booking['id']]));
    }
}
