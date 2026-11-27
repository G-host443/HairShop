<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Core\Config;
use App\Core\Database;
use App\Repositories\NotificationRepository;
use App\Services\Mail\MailerInterface;
use App\Services\Settings\SettingsService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * File d'envoi des e-mails.
 *
 * Les notifications sont enregistrées dans la même transaction que l'action qui les déclenche
 * (une réservation annulée ne laisse jamais partir un e-mail de confirmation), puis envoyées :
 *   - tout de suite après l'action, au mieux (sendForBooking) ;
 *   - sinon par la tâche planifiée « notifications:send », qui réessaie les échecs avec une attente croissante.
 */
final class NotificationService
{
    /** Événements envoyés à la cliente. */
    public const CUSTOMER_EVENTS = [
        'booking.created', 'booking.confirmed', 'booking.refused', 'booking.cancelled',
        'booking.cancelled_by_customer', 'booking.rescheduled', 'booking.reminder', 'booking.review_request',
    ];

    /** Événements qui alertent aussi l'administratrice. */
    public const ADMIN_EVENTS = ['booking.created', 'booking.cancelled_by_customer'];

    public function __construct(
        private readonly Database $db,
        private readonly NotificationRepository $notifications,
        private readonly NotificationRenderer $renderer,
        private readonly MailerInterface $mailer,
        private readonly SettingsService $settings,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Met en file les e-mails d'un événement de réservation (cliente et, selon l'événement, administratrice).
     *
     * @param array<string, mixed> $booking ligne de BookingRepository::find()
     * @param array<string, mixed> $extra   données propres à l'événement (ancienne date, motif…)
     */
    public function bookingEvent(string $event, array $booking, array $extra = []): void
    {
        $email = (string) ($booking['email'] ?? '');
        if (in_array($event, self::CUSTOMER_EVENTS, true) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->notifications->queue($event, $email, ['audience' => 'customer'] + $extra, (int) $booking['id']);
        }

        $admin = $this->settings->string('notifications.admin_email');
        if (in_array($event, self::ADMIN_EVENTS, true) && filter_var($admin, FILTER_VALIDATE_EMAIL)) {
            $this->notifications->queue($event, $admin, ['audience' => 'admin'] + $extra, (int) $booking['id']);
        }
    }

    /** Alerte « nouvel avis à modérer » pour l'administratrice. */
    public function reviewSubmitted(int $reviewId): void
    {
        $admin = $this->settings->string('notifications.admin_email');
        if (filter_var($admin, FILTER_VALIDATE_EMAIL)) {
            $this->notifications->queue('review.submitted', $admin, ['audience' => 'admin', 'review_id' => $reviewId], null);
        }
    }

    /** Alerte « nouveau message de contact » pour l'administratrice. */
    public function contactMessage(int $messageId): void
    {
        $admin = $this->settings->string('notifications.admin_email');
        if (filter_var($admin, FILTER_VALIDATE_EMAIL)) {
            $this->notifications->queue('contact.message', $admin, ['audience' => 'admin', 'message_id' => $messageId], null);
        }
    }

    /**
     * Met en file les rappels des rendez-vous confirmés qui commencent dans les N heures (paramètre),
     * une seule fois par rendez-vous.
     *
     * @return int nombre de rappels ajoutés
     */
    public function queueReminders(\DateTimeImmutable $now): int
    {
        $hours = max(1, $this->settings->int('notifications.reminder_hours', 24));
        $bookings = $this->db->fetchAll(
            "SELECT b.id, c.email FROM bookings b JOIN customers c ON c.id = b.customer_id
             WHERE b.status = 'confirmed' AND c.email IS NOT NULL
               AND b.starts_at > CAST(:now AS timestamptz)
               AND b.starts_at <= CAST(:now2 AS timestamptz) + make_interval(hours => :hours)",
            ['now' => $now->format(DATE_ATOM), 'now2' => $now->format(DATE_ATOM), 'hours' => $hours],
        );

        $queued = 0;
        foreach ($bookings as $booking) {
            if ($this->notifications->queueReminderOnce((int) $booking['id'], (string) $booking['email'])) {
                $queued++;
            }
        }

        return $queued;
    }

    /** Tente d'envoyer tout de suite quelques notifications en attente (sans jamais faire échouer l'appelant). */
    public function trySendNow(int $limit = 5): void
    {
        try {
            $this->sendDue($limit);
        } catch (Throwable $e) {
            $this->logger->error('Envoi immédiat des notifications impossible : ' . $e->getMessage());
        }
    }

    /** E-mail de test (administration). */
    public function test(string $recipient): int
    {
        return $this->notifications->queue('test', $recipient, ['audience' => 'admin'], null);
    }

    /**
     * Envoie tout de suite les notifications en attente d'une réservation (sans bloquer l'action en cas d'échec).
     */
    public function sendForBooking(int $bookingId): void
    {
        try {
            $this->sendDue(20, $bookingId);
        } catch (Throwable $e) {
            $this->logger->error('Envoi immédiat des notifications impossible : ' . $e->getMessage(), ['booking_id' => $bookingId]);
        }
    }

    /**
     * Envoie les notifications arrivées à échéance.
     *
     * @return array{sent: int, failed: int, cancelled: int}
     */
    public function sendDue(int $limit = 50, ?int $bookingId = null): array
    {
        $stats = ['sent' => 0, 'failed' => 0, 'cancelled' => 0];
        $maxAttempts = (int) $this->config->get('mail.max_attempts', 5);

        $this->db->transaction(function () use ($limit, $bookingId, $maxAttempts, &$stats): void {
            foreach ($this->notifications->lockDue($limit, $bookingId) as $row) {
                try {
                    $message = $this->renderer->render($row);
                } catch (NotificationObsolete $e) {
                    // Ex. rappel d'un rendez-vous annulé entre-temps : rien à envoyer.
                    $this->notifications->cancel((int) $row['id'], $e->getMessage());
                    $stats['cancelled']++;
                    continue;
                } catch (Throwable $e) {
                    $this->logger->error("Notification #{$row['id']} impossible à préparer : " . $e->getMessage(), ['exception' => $e]);
                    $this->notifications->markFailed((int) $row['id'], 'Préparation : ' . $e->getMessage(), $maxAttempts);
                    $stats['failed']++;
                    continue;
                }

                try {
                    $this->mailer->send($message);
                    $this->notifications->markSent((int) $row['id'], $message->subject);
                    $stats['sent']++;
                } catch (Throwable $e) {
                    $this->logger->warning("Notification #{$row['id']} non envoyée : " . $e->getMessage());
                    $this->notifications->markFailed((int) $row['id'], $e->getMessage(), $maxAttempts);
                    $stats['failed']++;
                }
            }
        });

        return $stats;
    }
}
