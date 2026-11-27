<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class BookingRepository
{
    private const SELECT = "SELECT b.*, c.first_name, c.last_name, c.email, c.phone,
                                   s.slug AS service_slug, st.label AS status_label, st.color AS status_color
                            FROM bookings b
                            JOIN customers c ON c.id = b.customer_id
                            JOIN services s ON s.id = b.service_id
                            JOIN booking_statuses st ON st.code = b.status";

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed> $data colonnes de la table bookings
     */
    public function insert(array $data): int
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(static fn (string $k): string => ':' . $k, array_keys($data)));

        return (int) $this->db->fetchValue("INSERT INTO bookings ({$columns}) VALUES ({$placeholders}) RETURNING id", $data);
    }

    /** @param list<array{id: int, label: string, price: ?string}> $options */
    public function insertOptions(int $bookingId, array $options): void
    {
        foreach ($options as $option) {
            $this->db->execute(
                'INSERT INTO booking_options (booking_id, option_id, label, price) VALUES (:b, :o, :l, :p)',
                ['b' => $bookingId, 'o' => $option['id'], 'l' => $option['label'], 'p' => $option['price']],
            );
        }
    }

    public function addHistory(int $bookingId, ?string $from, string $to, ?int $userId, ?string $note = null): void
    {
        $this->db->execute(
            'INSERT INTO booking_status_history (booking_id, from_status, to_status, changed_by, note) VALUES (:b, :f, :t, :u, :n)',
            ['b' => $bookingId, 'f' => $from, 't' => $to, 'u' => $userId, 'n' => $note],
        );
    }

    public function referenceExists(string $reference): bool
    {
        return (bool) $this->db->fetchValue('SELECT EXISTS (SELECT 1 FROM bookings WHERE reference = :r)', ['r' => $reference]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne(self::SELECT . ' WHERE b.id = :id', ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function findByReference(string $reference): ?array
    {
        return $this->db->fetchOne(self::SELECT . ' WHERE b.reference = :r', ['r' => $reference]);
    }

    /** @return array<string, mixed>|null */
    public function findByManageTokenHash(string $hash): ?array
    {
        return $this->db->fetchOne(self::SELECT . ' WHERE b.manage_token_hash = :h', ['h' => $hash]);
    }

    /** @return list<array<string, mixed>> */
    public function options(int $bookingId): array
    {
        return $this->db->fetchAll('SELECT option_id, label, price FROM booking_options WHERE booking_id = :id ORDER BY label', ['id' => $bookingId]);
    }

    /** @return list<array<string, mixed>> */
    public function history(int $bookingId): array
    {
        return $this->db->fetchAll(
            "SELECT h.*, st.label AS to_label, trim(u.first_name || ' ' || u.last_name) AS user_name
             FROM booking_status_history h
             JOIN booking_statuses st ON st.code = h.to_status
             LEFT JOIN users u ON u.id = h.changed_by
             WHERE h.booking_id = :id ORDER BY h.changed_at, h.id",
            ['id' => $bookingId],
        );
    }

    /**
     * @param array{from?: ?string, to?: ?string, status?: ?string, service?: ?int, q?: ?string} $filters dates AAAA-MM-JJ (heure locale)
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(array $filters, string $timezone, int $page = 1, int $perPage = 25): array
    {
        [$where, $params] = $this->where($filters, $timezone);

        $total = (int) $this->db->fetchValue('SELECT count(*) FROM bookings b JOIN customers c ON c.id = b.customer_id WHERE ' . $where, $params);
        $items = $this->db->fetchAll(
            self::SELECT . ' WHERE ' . $where . ' ORDER BY b.starts_at ' . (($filters['order'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC') . ', b.id LIMIT :limit OFFSET :offset',
            $params + ['limit' => $perPage, 'offset' => max(0, ($page - 1) * $perPage)],
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Réservations d'une période (calendrier), annulées et refusées comprises si demandé.
     *
     * @return list<array<string, mixed>>
     */
    public function between(string $fromIso, string $toIso, bool $includeInactive = false): array
    {
        return $this->db->fetchAll(
            self::SELECT . " WHERE b.during && tstzrange(CAST(:f AS timestamptz), CAST(:t AS timestamptz), '[)')"
            . ($includeInactive ? '' : " AND b.status NOT IN ('cancelled', 'refused')")
            . ' ORDER BY b.starts_at',
            ['f' => $fromIso, 't' => $toIso],
        );
    }

    public function updateStatus(int $id, string $status, ?string $cancelReason = null): void
    {
        $this->db->execute(
            "UPDATE bookings SET status = :s,
                    cancelled_at = CASE WHEN :s2 = 'cancelled' THEN now() ELSE NULL END,
                    cancel_reason = CASE WHEN :s3 = 'cancelled' THEN :reason ELSE NULL END
             WHERE id = :id",
            ['s' => $status, 's2' => $status, 's3' => $status, 'reason' => $cancelReason, 'id' => $id],
        );
    }

    public function reschedule(int $id, string $startsIso, string $endsIso): void
    {
        $this->db->execute(
            'UPDATE bookings SET starts_at = CAST(:s AS timestamptz), ends_at = CAST(:e AS timestamptz) WHERE id = :id',
            ['s' => $startsIso, 'e' => $endsIso, 'id' => $id],
        );
    }

    public function setFinalTotal(int $id, ?string $amount): void
    {
        $this->db->execute('UPDATE bookings SET final_total = :a WHERE id = :id', ['a' => $amount, 'id' => $id]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function where(array $filters, string $timezone): array
    {
        $where = ['TRUE'];
        $params = [];

        if (!empty($filters['from'])) {
            $where[] = '(b.starts_at AT TIME ZONE :tz1)::date >= CAST(:from AS date)';
            $params += ['tz1' => $timezone, 'from' => $filters['from']];
        }
        if (!empty($filters['to'])) {
            $where[] = '(b.starts_at AT TIME ZONE :tz2)::date <= CAST(:to AS date)';
            $params += ['tz2' => $timezone, 'to' => $filters['to']];
        }
        if (!empty($filters['status'])) {
            $where[] = 'b.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['service'])) {
            $where[] = 'b.service_id = :service';
            $params['service'] = (int) $filters['service'];
        }
        if (!empty($filters['q'])) {
            $like = '%' . addcslashes((string) $filters['q'], '%_\\') . '%';
            $where[] = "(c.first_name || ' ' || c.last_name ILIKE :q1 OR c.email ILIKE :q2 OR c.phone ILIKE :q3 OR b.reference ILIKE :q4)";
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
        }

        return [implode(' AND ', $where), $params];
    }
}
