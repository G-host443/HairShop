<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Security\Encrypter;
use App\Core\View;
use App\Repositories\BookingRepository;
use App\Repositories\UserRepository;
use App\Services\Auth\EmailVerification;
use App\Services\Booking\BookingStatus;
use App\Services\Mail\MailMessage;
use App\Services\Settings\SettingsService;
use DateTimeImmutable;

/**
 * Construit l'e-mail d'une notification au moment de l'envoi, à partir des données à jour.
 */
final class NotificationRenderer
{
    /** Événements dont l'envoi n'a de sens que si le rendez-vous est toujours actif. */
    private const NEEDS_ACTIVE = ['booking.created', 'booking.confirmed', 'booking.rescheduled', 'booking.reminder'];

    public function __construct(
        private readonly View $view,
        private readonly BookingRepository $bookings,
        private readonly SettingsService $settings,
        private readonly Encrypter $encrypter,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly EmailVerification $verification,
    ) {
    }

    /**
     * @param array{id: int, event: string, recipient: string, payload: array<string, mixed>, booking_id: ?int} $row
     * @throws NotificationObsolete
     */
    public function render(array $row): MailMessage
    {
        $event = (string) $row['event'];
        $audience = (string) ($row['payload']['audience'] ?? 'customer');
        $data = $this->common() + ['payload' => $row['payload'], 'audience' => $audience];

        if ($event === 'test') {
            return $this->message($row, 'E-mail de test — ' . $data['site_name'], 'test', $data);
        }

        if ($event === 'account.verify') {
            $user = $this->users->findById((int) ($row['payload']['user_id'] ?? 0)) ?? throw new NotificationObsolete('Compte supprimé.');
            if ($user->isEmailVerified() || mb_strtolower($user->email) !== mb_strtolower((string) $row['recipient'])) {
                throw new NotificationObsolete('Adresse déjà vérifiée ou modifiée depuis.');
            }
            $url = $data['base_url'] . '/compte/verifier/' . $this->verification->token($user, (int) ($row['payload']['expires'] ?? 0));

            return $this->message($row, 'Confirmez votre adresse e-mail — ' . $data['site_name'], 'account-verify', $data + ['user' => $user, 'verify_url' => $url, 'hours' => EmailVerification::VALIDITY_HOURS]);
        }

        if ($event === 'review.submitted') {
            $review = $this->db->fetchOne(
                'SELECT r.*, b.reference, b.service_label FROM reviews r LEFT JOIN bookings b ON b.id = r.booking_id WHERE r.id = :id',
                ['id' => (int) ($row['payload']['review_id'] ?? 0)],
            ) ?? throw new NotificationObsolete('Avis supprimé.');
            if ($review['status'] !== 'pending') {
                throw new NotificationObsolete('Avis déjà modéré.');
            }

            return $this->message($row, "Nouvel avis à modérer — {$review['author_name']} ({$review['rating']}/5)", 'admin-review-submitted',
                $data + ['review' => $review, 'admin_url' => $data['base_url'] . '/admin/avis']);
        }

        if ($event === 'contact.message') {
            $message = $this->db->fetchOne('SELECT * FROM contact_messages WHERE id = :id', ['id' => (int) ($row['payload']['message_id'] ?? 0)])
                ?? throw new NotificationObsolete('Message de contact supprimé.');

            return $this->message($row, 'Nouveau message de ' . $message['name'], 'admin-contact-message', $data + ['message' => $message], (string) $message['email']);
        }

        $booking = $row['booking_id'] !== null ? $this->bookings->find((int) $row['booking_id']) : null;
        if ($booking === null) {
            throw new NotificationObsolete('Rendez-vous supprimé.');
        }
        $this->assertStillRelevant($event, $booking);

        $isHome = $data['location_type'] === 'home';
        $confirmed = $booking['status'] === BookingStatus::CONFIRMED;
        $token = $booking['manage_token_encrypted'] !== null ? $this->encrypter->decrypt((string) $booking['manage_token_encrypted']) : null;

        $data += [
            'booking' => $booking,
            'options' => $this->bookings->options((int) $booking['id']),
            'manage_url' => $token !== null ? $data['base_url'] . '/rdv/' . $token : null,
            'admin_url' => $data['base_url'] . '/admin/reservations/' . $booking['id'],
            // À domicile, l'adresse exacte n'est donnée qu'une fois le rendez-vous confirmé.
            'show_address' => $data['address'] !== '' && (!$isHome || $confirmed),
            'is_confirmed' => $confirmed,
        ];

        $reference = (string) $booking['reference'];
        $date = (new DateTimeImmutable((string) $booking['starts_at']))->setTimezone($this->clock->zone())->format('d/m');

        if ($audience === 'admin') {
            [$subject, $template] = match ($event) {
                'booking.cancelled_by_customer' => ["Annulation par la cliente — {$booking['first_name']} {$booking['last_name']}, {$date}", 'admin-booking-cancelled'],
                default => [($booking['status'] === BookingStatus::PENDING ? 'Nouvelle demande' : 'Nouveau rendez-vous') . " — {$booking['first_name']} {$booking['last_name']}, {$date}", 'admin-booking-created'],
            };

            return $this->message($row, $subject, $template, $data, $booking['email'] ?: null);
        }

        [$subject, $template] = match ($event) {
            'booking.created' => [$confirmed ? "Votre rendez-vous du {$date} est confirmé ({$reference})" : "Votre demande de rendez-vous est bien reçue ({$reference})", 'booking-created'],
            'booking.confirmed' => ["Votre rendez-vous du {$date} est confirmé ({$reference})", 'booking-confirmed'],
            'booking.refused' => ["Votre demande de rendez-vous ({$reference})", 'booking-refused'],
            'booking.cancelled', 'booking.cancelled_by_customer' => ["Rendez-vous annulé ({$reference})", 'booking-cancelled'],
            'booking.rescheduled' => ["Votre rendez-vous a été déplacé au {$date} ({$reference})", 'booking-rescheduled'],
            'booking.reminder' => ["Rappel : votre rendez-vous du {$date}", 'booking-reminder'],
            'booking.review_request' => ['Votre avis compte — ' . $data['site_name'], 'booking-review-request'],
            default => throw new NotificationObsolete("Événement inconnu : {$event}"),
        };

        return $this->message($row, $subject, $template, $data);
    }

    /** @param array<string, mixed> $booking */
    private function assertStillRelevant(string $event, array $booking): void
    {
        $status = (string) $booking['status'];

        if (in_array($event, self::NEEDS_ACTIVE, true) && !BookingStatus::isActive($status)) {
            throw new NotificationObsolete('Rendez-vous plus actif (' . BookingStatus::label($status) . ').');
        }
        if ($event === 'booking.review_request') {
            if ($status !== BookingStatus::COMPLETED) {
                throw new NotificationObsolete('Rendez-vous plus terminé.');
            }
            if ($this->db->fetchValue('SELECT 1 FROM reviews WHERE booking_id = :b', ['b' => $booking['id']]) !== null) {
                throw new NotificationObsolete('Avis déjà laissé.');
            }
        }
        if ($event === 'booking.reminder') {
            if ($status !== BookingStatus::CONFIRMED) {
                throw new NotificationObsolete('Rappel réservé aux rendez-vous confirmés.');
            }
            if (new DateTimeImmutable((string) $booking['starts_at']) <= $this->clock->now()) {
                throw new NotificationObsolete('Rendez-vous déjà passé.');
            }
        }
    }

    /** @return array<string, mixed> */
    private function common(): array
    {
        $whatsapp = $this->settings->string('contact.whatsapp_number');

        return [
            'site_name' => $this->settings->string('general.site_name', (string) $this->config->get('app.name')),
            'base_url' => rtrim((string) $this->config->get('app.url'), '/'),
            'location_type' => $this->settings->string('contact.location_type', 'home'),
            'public_location' => $this->settings->string('contact.public_location'),
            'address' => $this->settings->string('contact.private_address'),
            'phone' => $this->settings->string('contact.phone'),
            'whatsapp' => preg_match('/^[1-9][0-9]{7,14}$/', $whatsapp) ? $whatsapp : null,
            'contact_email' => $this->settings->string('contact.email'),
            'cancellation_hours' => $this->settings->int('booking.cancellation_hours', 48),
            'rules' => array_column($this->db->fetchAll('SELECT body FROM booking_rules WHERE is_active ORDER BY position, id'), 'body'),
        ];
    }

    /** Version texte : espaces de fin retirés, une seule ligne vide au maximum entre deux paragraphes. */
    public static function tidyText(string $text): string
    {
        $lines = array_map('rtrim', explode("\n", str_replace("\r\n", "\n", $text)));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    /** @param array<string, mixed> $data */
    private function message(array $row, string $subject, string $template, array $data, ?string $replyTo = null): MailMessage
    {
        $data['subject'] = $subject;

        return new MailMessage(
            to: (string) $row['recipient'],
            subject: $subject,
            html: $this->view->render("emails/{$template}.html.twig", $data),
            text: self::tidyText($this->view->render("emails/{$template}.txt.twig", $data)),
            replyTo: $replyTo ?? ($data['contact_email'] !== '' ? $data['contact_email'] : null),
        );
    }
}
