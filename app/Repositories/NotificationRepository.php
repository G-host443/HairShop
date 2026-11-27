<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class NotificationRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $payload */
    public function queue(string $event, string $recipient, array $payload, ?int $bookingId, ?string $scheduledAtIso = null): int
    {
        return (int) $this->db->fetchValue(
            "INSERT INTO notifications (channel, event, recipient, payload, booking_id, scheduled_at)
             VALUES ('email', :event, :recipient, CAST(:payload AS jsonb), :booking, COALESCE(CAST(:at AS timestamptz), now()))
             RETURNING id",
            [
                'event' => $event,
                'recipient' => $recipient,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'booking' => $bookingId,
                'at' => $scheduledAtIso,
            ],
        );
    }

    /**
     * Rappel d'un rendez-vous, une seule fois par réservation (index unique partiel).
     *
     * @return bool false si un rappel existait déjà
     */
    public function queueReminderOnce(int $bookingId, string $recipient): bool
    {
        return $this->db->execute(
            "INSERT INTO notifications (channel, event, recipient, payload, booking_id)
             VALUES ('email', 'booking.reminder', :recipient, '{\"audience\": \"customer\"}'::jsonb, :booking)
             ON CONFLICT (booking_id, channel) WHERE event = 'booking.reminder' AND status <> 'cancelled' DO NOTHING",
            ['recipient' => $recipient, 'booking' => $bookingId],
        ) === 1;
    }

    /**
     * Notifications à envoyer, verrouillées pour ce processus (deux tâches simultanées ne prennent pas les mêmes).
     * À appeler dans une transaction.
     *
     * @return list<array<string, mixed>>
     */
    public function lockDue(int $limit, ?int $bookingId = null): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id, event, recipient, payload::text AS payload, booking_id, attempts
             FROM notifications
             WHERE status = 'queued' AND scheduled_at <= now()
               AND (CAST(:booking AS bigint) IS NULL OR booking_id = CAST(:booking2 AS bigint))
             ORDER BY scheduled_at, id
             LIMIT :limit
             FOR UPDATE SKIP LOCKED",
            ['booking' => $bookingId, 'booking2' => $bookingId, 'limit' => $limit],
        );

        foreach ($rows as &$row) {
            $row['payload'] = json_decode((string) $row['payload'], true) ?: [];
        }

        return $rows;
    }

    public function markSent(int $id, string $subject): void
    {
        $this->db->execute(
            "UPDATE notifications SET status = 'sent', sent_at = now(), subject = :subject, error = NULL,
                    attempts = attempts + 1 WHERE id = :id",
            ['subject' => $subject, 'id' => $id],
        );
    }

    /** Échec : nouvelle tentative plus tard (attente croissante), ou abandon après $maxAttempts. */
    public function markFailed(int $id, string $error, int $maxAttempts): void
    {
        $this->db->execute(
            "UPDATE notifications SET
                attempts = attempts + 1,
                error = :error,
                status = CASE WHEN attempts + 1 >= :max THEN 'failed' ELSE 'queued' END,
                scheduled_at = now() + make_interval(mins => CAST(power(2, attempts + 1) * 5 AS integer))
             WHERE id = :id",
            ['error' => mb_substr($error, 0, 1000), 'max' => $maxAttempts, 'id' => $id],
        );
    }

    public function cancel(int $id, string $reason): void
    {
        $this->db->execute("UPDATE notifications SET status = 'cancelled', error = :r WHERE id = :id", ['r' => $reason, 'id' => $id]);
    }

    /** Remet une notification échouée dans la file (bouton « Renvoyer »). */
    public function retry(int $id): bool
    {
        return $this->db->execute(
            "UPDATE notifications SET status = 'queued', scheduled_at = now(), error = NULL WHERE id = :id AND status IN ('failed', 'cancelled')",
            ['id' => $id],
        ) === 1;
    }

    /** @return list<array<string, mixed>> */
    public function recent(?string $status, int $limit = 100): array
    {
        return $this->db->fetchAll(
            'SELECT n.*, b.reference FROM notifications n LEFT JOIN bookings b ON b.id = n.booking_id
             WHERE CAST(:status AS text) IS NULL OR n.status = CAST(:status2 AS text)
             ORDER BY n.created_at DESC, n.id DESC LIMIT :limit',
            ['status' => $status, 'status2' => $status, 'limit' => $limit],
        );
    }

    /** @return list<array<string, mixed>> */
    public function forBooking(int $bookingId): array
    {
        return $this->db->fetchAll(
            'SELECT id, event, recipient, status, subject, attempts, error, scheduled_at, sent_at, created_at
             FROM notifications WHERE booking_id = :id ORDER BY created_at, id',
            ['id' => $bookingId],
        );
    }

    /** @return array<string, int> nombre par statut */
    public function countsByStatus(): array
    {
        $counts = ['queued' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0];
        foreach ($this->db->fetchAll('SELECT status, count(*) AS n FROM notifications GROUP BY status') as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }
}
