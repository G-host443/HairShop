<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Fiches clientes vues depuis l'administration : recherche, historique, prestations réalisées, notes internes.
 */
final class CustomerAdminRepository
{
    public const SORTS = [
        'recent' => 'Dernier rendez-vous',
        'name' => 'Nom',
        'bookings' => 'Nombre de rendez-vous',
        'new' => 'Fiches récentes',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(string $q, string $sort, int $page, int $perPage): array
    {
        $where = 'TRUE';
        $params = [];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $digits = preg_replace('/\D/', '', $q) ?? '';
            $where = "(c.first_name || ' ' || c.last_name ILIKE :q1 OR c.last_name || ' ' || c.first_name ILIKE :q2 OR c.email ILIKE :q3"
                . ($digits !== '' && strlen($digits) >= 3 ? " OR regexp_replace(COALESCE(c.phone, ''), '\\D', '', 'g') LIKE :phone" : '') . ')';
            $params = ['q1' => $like, 'q2' => $like, 'q3' => $like];
            if ($digits !== '' && strlen($digits) >= 3) {
                // 06 12 34 56 78 et +33 6 12 34 56 78 : on compare les chiffres sans l'indicatif.
                $params['phone'] = '%' . (str_starts_with($digits, '33') ? substr($digits, 2) : ltrim($digits, '0')) . '%';
            }
        }

        $order = match ($sort) {
            'name' => 'lower(c.last_name), lower(c.first_name)',
            'bookings' => 'stats.bookings_count DESC, stats.last_booking DESC NULLS LAST',
            'new' => 'c.created_at DESC',
            default => 'stats.last_booking DESC NULLS LAST, c.created_at DESC',
        };

        $total = (int) $this->db->fetchValue("SELECT count(*) FROM customers c WHERE {$where}", $params);
        $items = $this->db->fetchAll(
            "SELECT c.id, c.first_name, c.last_name, c.email, c.phone, c.user_id, c.marketing_consent, c.created_at,
                    stats.bookings_count, stats.completed_count, stats.last_booking, stats.spent
             FROM customers c
             LEFT JOIN LATERAL (
                 SELECT count(*) AS bookings_count,
                        count(*) FILTER (WHERE b.status = 'completed') AS completed_count,
                        max(b.starts_at) FILTER (WHERE b.status NOT IN ('cancelled', 'refused')) AS last_booking,
                        COALESCE(sum(COALESCE(b.final_total, b.estimated_total)) FILTER (WHERE b.status = 'completed'), 0) AS spent
                 FROM bookings b WHERE b.customer_id = c.id
             ) stats ON true
             WHERE {$where}
             ORDER BY {$order}, c.id DESC
             LIMIT :limit OFFSET :offset",
            $params + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        return ['items' => $items, 'total' => $total];
    }

    /** @return array<string, mixed>|null fiche complète avec ses statistiques */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT c.*, u.email AS account_email, u.email_verified_at, u.last_login_at,
                    (SELECT count(*) FROM bookings b WHERE b.customer_id = c.id) AS bookings_count,
                    (SELECT count(*) FROM bookings b WHERE b.customer_id = c.id AND b.status = 'completed') AS completed_count,
                    (SELECT count(*) FROM bookings b WHERE b.customer_id = c.id AND b.status IN ('cancelled', 'no_show')) AS cancelled_count,
                    (SELECT count(*) FROM bookings b WHERE b.customer_id = c.id AND b.status = 'no_show') AS no_show_count,
                    (SELECT COALESCE(sum(COALESCE(b.final_total, b.estimated_total)), 0) FROM bookings b WHERE b.customer_id = c.id AND b.status = 'completed') AS spent
             FROM customers c LEFT JOIN users u ON u.id = c.user_id WHERE c.id = :id",
            ['id' => $id],
        );
    }

    /** @return list<array<string, mixed>> tous les rendez-vous, les plus récents d'abord */
    public function bookings(int $customerId): array
    {
        return $this->db->fetchAll(
            'SELECT b.id, b.reference, b.service_label, b.starts_at, b.status, b.estimated_total, b.final_total, b.discount_amount, b.source,
                    st.label AS status_label, st.color AS status_color
             FROM bookings b JOIN booking_statuses st ON st.code = b.status
             WHERE b.customer_id = :c ORDER BY b.starts_at DESC',
            ['c' => $customerId],
        );
    }

    /** @return list<array{name: string, count: int, last: string}> prestations déjà réalisées (rendez-vous terminés) */
    public function servicesDone(int $customerId): array
    {
        return array_map(static fn (array $r): array => ['name' => (string) $r['name'], 'count' => (int) $r['n'], 'last' => (string) $r['last']],
            $this->db->fetchAll(
                "SELECT s.name, count(*) AS n, max(b.starts_at) AS last
                 FROM bookings b JOIN services s ON s.id = b.service_id
                 WHERE b.customer_id = :c AND b.status = 'completed'
                 GROUP BY s.id, s.name ORDER BY n DESC, last DESC",
                ['c' => $customerId],
            ));
    }

    /** @return list<string> */
    public function favorites(int $customerId): array
    {
        return array_column($this->db->fetchAll(
            'SELECT s.name FROM favorites f JOIN services s ON s.id = f.service_id WHERE f.customer_id = :c ORDER BY s.name',
            ['c' => $customerId],
        ), 'name');
    }

    /** @return list<array<string, mixed>> */
    public function reviews(int $customerId): array
    {
        return $this->db->fetchAll('SELECT id, rating, comment, status, created_at FROM reviews WHERE customer_id = :c ORDER BY created_at DESC', ['c' => $customerId]);
    }

    /** @return list<array<string, mixed>> */
    public function notes(int $customerId): array
    {
        return $this->db->fetchAll(
            "SELECT n.id, n.body, n.created_at, n.author_id, trim(u.first_name || ' ' || u.last_name) AS author_name
             FROM customer_notes n LEFT JOIN users u ON u.id = n.author_id
             WHERE n.customer_id = :c ORDER BY n.created_at DESC, n.id DESC",
            ['c' => $customerId],
        );
    }

    public function addNote(int $customerId, ?int $authorId, string $body): int
    {
        return (int) $this->db->fetchValue(
            'INSERT INTO customer_notes (customer_id, author_id, body) VALUES (:c, :a, :b) RETURNING id',
            ['c' => $customerId, 'a' => $authorId, 'b' => $body],
        );
    }

    /** @return array<string, mixed>|null */
    public function findNote(int $customerId, int $noteId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM customer_notes WHERE id = :id AND customer_id = :c', ['id' => $noteId, 'c' => $customerId]);
    }

    public function deleteNote(int $noteId): void
    {
        $this->db->execute('DELETE FROM customer_notes WHERE id = :id', ['id' => $noteId]);
    }

    /** @param array{first_name: string, last_name: string, email: ?string, phone: ?string, marketing_consent: bool} $data */
    public function update(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE customers SET first_name = :f, last_name = :l, email = :e, phone = :p, marketing_consent = :m WHERE id = :id',
            ['id' => $id, 'f' => $data['first_name'], 'l' => $data['last_name'], 'e' => $data['email'], 'p' => $data['phone'], 'm' => $data['marketing_consent']],
        );
    }

    /** Autre fiche avec la même adresse e-mail (doublon probable). */
    public function emailUsedByOther(string $email, int $exceptId): bool
    {
        return (bool) $this->db->fetchValue('SELECT EXISTS (SELECT 1 FROM customers WHERE email = :e AND id <> :id)', ['e' => $email, 'id' => $exceptId]);
    }
}
