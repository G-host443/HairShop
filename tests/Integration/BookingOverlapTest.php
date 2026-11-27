<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Protection contre les doubles réservations par la contrainte d'exclusion bookings_no_overlap.
 */
final class BookingOverlapTest extends DatabaseTestCase
{
    private const EXCLUSION_VIOLATION = '23P01';

    private int $serviceId;
    private int $customerId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->serviceId = $this->createService();
        $this->customerId = $this->createCustomer();
    }

    private function insertSql(): string
    {
        return "INSERT INTO bookings (reference, customer_id, service_id, service_label, starts_at, ends_at, status, cancelled_at)
                VALUES (:ref, :customer, :service, 'Prestation test', :starts, :ends, :status,
                        CASE WHEN :status2 = 'cancelled' THEN now() END)";
    }

    /** @return array<string, mixed> */
    private function params(string $starts, string $ends, string $status = 'confirmed'): array
    {
        return [
            'ref' => sprintf('HSG-TEST%02d', ++$this->sequence),
            'customer' => $this->customerId,
            'service' => $this->serviceId,
            'starts' => $starts,
            'ends' => $ends,
            'status' => $status,
            'status2' => $status,
        ];
    }

    private function book(string $starts, string $ends, string $status = 'confirmed'): void
    {
        $this->db->execute($this->insertSql(), $this->params($starts, $ends, $status));
    }

    public function testOverlappingActiveBookingIsRejected(): void
    {
        $this->book('2027-03-10 10:00+01', '2027-03-10 14:00+01');

        $this->assertSqlFails(
            self::EXCLUSION_VIOLATION,
            $this->insertSql(),
            $this->params('2027-03-10 13:00+01', '2027-03-10 15:00+01', 'pending'),
            'Un rendez-vous qui chevauche un rendez-vous confirmé doit être refusé.',
        );
    }

    public function testAdjacentBookingsAreAllowed(): void
    {
        // Plage semi-ouverte [début, fin) : finir à 14:00 et commencer à 14:00 ne se chevauchent pas.
        $this->book('2027-03-10 10:00+01', '2027-03-10 14:00+01');
        $this->book('2027-03-10 14:00+01', '2027-03-10 16:00+01');

        self::assertSame(2, (int) $this->db->fetchValue('SELECT count(*) FROM bookings'));
    }

    public function testInactiveStatusesDoNotBlockTheSlot(): void
    {
        foreach (['cancelled', 'refused', 'no_show', 'completed'] as $status) {
            $this->book('2027-03-11 10:00+01', '2027-03-11 14:00+01', $status);
        }

        $this->book('2027-03-11 10:00+01', '2027-03-11 14:00+01', 'confirmed');

        self::assertSame(5, (int) $this->db->fetchValue('SELECT count(*) FROM bookings'));
    }

    public function testReactivatingACancelledBookingOnATakenSlotIsRejected(): void
    {
        $this->book('2027-03-12 10:00+01', '2027-03-12 12:00+01', 'cancelled');
        $this->book('2027-03-12 11:00+01', '2027-03-12 13:00+01', 'confirmed');

        $this->assertSqlFails(
            self::EXCLUSION_VIOLATION,
            "UPDATE bookings SET status = 'confirmed', cancelled_at = NULL WHERE reference = :ref",
            ['ref' => 'HSG-TEST01'],
        );
    }

    public function testStatusesBlockingFlagMatchesTheConstraint(): void
    {
        $blocking = array_column(
            $this->db->fetchAll('SELECT code FROM booking_statuses WHERE blocks_slot ORDER BY code'),
            'code',
        );

        self::assertSame(['confirmed', 'in_progress', 'pending'], $blocking);
    }

    public function testInvalidPeriodAndReferenceAreRejected(): void
    {
        // Fin avant début : rejeté dès le calcul de la colonne générée « during » (plage invalide, SQLSTATE 22000).
        $this->assertSqlFails('22000', $this->insertSql(), $this->params('2027-03-13 14:00+01', '2027-03-13 10:00+01'));

        $params = $this->params('2027-03-13 10:00+01', '2027-03-13 11:00+01');
        $params['ref'] = 'invalide';
        $this->assertSqlFails('23514', $this->insertSql(), $params);
    }
}
