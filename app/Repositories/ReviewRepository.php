<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class ReviewRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT r.*, b.reference AS booking_reference, b.service_label
             FROM reviews r LEFT JOIN bookings b ON b.id = r.booking_id WHERE r.id = :id',
            ['id' => $id],
        );
    }

    /** @return array<string, mixed>|null */
    public function findByBooking(int $bookingId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM reviews WHERE booking_id = :b', ['b' => $bookingId]);
    }

    /** @return list<array<string, mixed>> avis d'un statut, les plus récents d'abord (administration) */
    public function byStatus(string $status, int $limit = 100): array
    {
        return $this->db->fetchAll(
            'SELECT r.*, b.reference AS booking_reference, b.service_label
             FROM reviews r LEFT JOIN bookings b ON b.id = r.booking_id
             WHERE r.status = :status ORDER BY r.created_at DESC, r.id DESC LIMIT :limit',
            ['status' => $status, 'limit' => $limit],
        );
    }

    /** @return array<string, int> nombre d'avis par statut */
    public function counts(): array
    {
        $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        foreach ($this->db->fetchAll('SELECT status, count(*) AS n FROM reviews GROUP BY status') as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /** @return list<array<string, mixed>> avis publiés (site public) */
    public function approved(int $limit, int $offset = 0, bool $featuredFirst = true): array
    {
        return $this->db->fetchAll(
            'SELECT r.id, r.author_name, r.rating, r.comment, r.photo_media_id, r.is_featured, r.created_at, b.service_label
             FROM reviews r LEFT JOIN bookings b ON b.id = r.booking_id
             WHERE r.status = \'approved\'
             ORDER BY ' . ($featuredFirst ? 'r.is_featured DESC, ' : '') . 'r.created_at DESC, r.id DESC
             LIMIT :limit OFFSET :offset',
            ['limit' => $limit, 'offset' => $offset],
        );
    }

    /** @return array{count: int, average: float} */
    public function approvedStats(): array
    {
        $row = $this->db->fetchOne("SELECT count(*) AS n, COALESCE(avg(rating), 0) AS avg FROM reviews WHERE status = 'approved'");

        return ['count' => (int) $row['n'], 'average' => round((float) $row['avg'], 1)];
    }

    /** @return array<int, int> nombre d'avis publiés par note, de 5 à 1 */
    public function ratingDistribution(): array
    {
        $distribution = array_fill_keys([5, 4, 3, 2, 1], 0);
        foreach ($this->db->fetchAll("SELECT rating, count(*) AS n FROM reviews WHERE status = 'approved' GROUP BY rating") as $row) {
            $distribution[(int) $row['rating']] = (int) $row['n'];
        }

        return $distribution;
    }

    /** @param array{customer_id: ?int, booking_id: ?int, author_name: string, rating: int, comment: string, status: string, moderated_by?: ?int} $data */
    public function create(array $data): int
    {
        $moderated = $data['status'] !== 'pending';

        return (int) $this->db->fetchValue(
            'INSERT INTO reviews (customer_id, booking_id, author_name, rating, comment, status, moderated_at, moderated_by)
             VALUES (:customer, :booking, :author, :rating, :comment, :status, CASE WHEN :moderated THEN now() END, :by) RETURNING id',
            [
                'customer' => $data['customer_id'],
                'booking' => $data['booking_id'],
                'author' => $data['author_name'],
                'rating' => $data['rating'],
                'comment' => $data['comment'],
                'status' => $data['status'],
                'moderated' => $moderated,
                'by' => $data['moderated_by'] ?? null,
            ],
        );
    }

    /** Un avis refusé ou en attente ne peut pas rester mis en avant (contrainte de la table). */
    public function moderate(int $id, string $status, ?int $userId): void
    {
        $this->db->execute(
            'UPDATE reviews SET status = :status, is_featured = (is_featured AND :status2 = \'approved\'), moderated_at = now(), moderated_by = :by WHERE id = :id',
            ['id' => $id, 'status' => $status, 'status2' => $status, 'by' => $userId],
        );
    }

    public function setFeatured(int $id, bool $featured): void
    {
        $this->db->execute("UPDATE reviews SET is_featured = :f WHERE id = :id AND status = 'approved'", ['id' => $id, 'f' => $featured]);
    }

    public function updateDisplay(int $id, string $authorName, ?int $photoMediaId): void
    {
        $this->db->execute(
            'UPDATE reviews SET author_name = :author, photo_media_id = :photo WHERE id = :id',
            ['id' => $id, 'author' => $authorName, 'photo' => $photoMediaId],
        );
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM reviews WHERE id = :id', ['id' => $id]);
    }
}
