<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Security\Encrypter;
use App\Repositories\BookingRepository;
use App\Repositories\CustomerRepository;
use App\Services\Catalog\PricingException;
use App\Services\Catalog\PricingService;
use App\Services\Notification\NotificationService;
use App\Services\Settings\SettingsService;
use DateTimeImmutable;
use PDOException;

/**
 * Création et cycle de vie des réservations.
 *
 * Protection contre les doubles réservations, en deux niveaux :
 *   1. verrou PostgreSQL par journée (pg_advisory_xact_lock) : deux réservations du même jour
 *      sont traitées l'une après l'autre, et la disponibilité est revérifiée sous ce verrou ;
 *   2. contrainte d'exclusion bookings_no_overlap : garantie absolue en dernier recours (SQLSTATE 23P01).
 */
final class BookingService
{
    private const REFERENCE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sans O, 0, I, 1

    public function __construct(
        private readonly Database $db,
        private readonly BookingRepository $bookings,
        private readonly CustomerRepository $customers,
        private readonly PricingService $pricing,
        private readonly AvailabilityService $availability,
        private readonly SettingsService $settings,
        private readonly Clock $clock,
        private readonly NotificationService $notifications,
        private readonly Encrypter $encrypter,
    ) {
    }

    /**
     * Met en file l'e-mail d'un événement (dans la transaction en cours) — voir NotificationService.
     *
     * @param array<string, mixed> $extra
     */
    private function notify(string $event, int $bookingId, array $extra = []): void
    {
        $booking = $this->bookings->find($bookingId);
        if ($booking !== null) {
            $this->notifications->bookingEvent($event, $booking, $extra);
        }
    }

    /**
     * @throws BookingException
     */
    public function create(BookingRequest $request): BookingResult
    {
        try {
            $quote = $this->pricing->quote($request->serviceId, $request->variantId, $request->lengthId, $request->optionIds);
        } catch (PricingException $e) {
            throw new BookingException($e->getMessage(), 'service');
        }

        if ($request->source === 'web' && $this->settings->bool('booking.require_rules_acceptance', true) && !$request->rulesAccepted) {
            throw new BookingException('Merci de confirmer avoir lu les informations « À savoir avant de venir ».', 'rules');
        }

        $start = $request->startsAt->setTimezone($this->clock->zone());
        $end = $start->modify("+{$quote->durationMinutes} minutes");

        $status = $request->forcedStatus
            ?? ($this->settings->bool('booking.auto_confirm') && !$quote->isQuote() ? BookingStatus::CONFIRMED : BookingStatus::PENDING);
        if (!BookingStatus::isActive($status)) {
            throw new BookingException('Statut initial invalide.', 'status');
        }

        $token = bin2hex(random_bytes(32));

        try {
            $id = $this->db->transaction(function () use ($request, $quote, $start, $end, $status, $token): int {
                $this->lockDay($start);

                // Revérification sous verrou : horaires, fermetures, créneaux bloqués, autres rendez-vous.
                if (!$request->ignoreSchedule && !$this->availability->isAvailable($start, $quote->durationMinutes)) {
                    throw new SlotUnavailableException();
                }

                $customerId = $request->customerId ?? $this->customers->findOrCreate(
                    $request->firstName,
                    $request->lastName,
                    $request->email,
                    $request->phone,
                    $request->marketingConsent,
                );

                $id = $this->bookings->insert([
                    'reference' => $this->newReference(),
                    'manage_token_hash' => hash('sha256', $token),
                    'manage_token_encrypted' => $this->encrypter->encrypt($token),
                    'customer_id' => $customerId,
                    'service_id' => $quote->serviceId,
                    'variant_id' => $quote->variantId,
                    'length_id' => $quote->lengthId,
                    'service_label' => $quote->label,
                    'starts_at' => $start->format(DATE_ATOM),
                    'ends_at' => $end->format(DATE_ATOM),
                    'status' => $status,
                    'estimated_total' => $quote->total(),
                    'promotion_id' => $quote->promotionId(),
                    'discount_amount' => number_format($quote->discount(), 2, '.', ''),
                    'customer_comment' => $request->comment,
                    'rules_accepted_at' => $request->rulesAccepted ? $this->clock->now()->format(DATE_ATOM) : null,
                    'source' => $request->source,
                    'created_by' => $request->createdBy,
                ]);

                $this->bookings->insertOptions($id, array_map(
                    static fn (array $o): array => ['id' => $o['id'], 'label' => $o['label'], 'price' => $o['price']],
                    $quote->options,
                ));
                $this->bookings->addHistory($id, null, $status, $request->createdBy, $request->source === 'web' ? 'Réservation en ligne' : 'Saisie manuelle');
                $this->notify('booking.created', $id);

                return $id;
            });
        } catch (PDOException $e) {
            // 23P01 : un autre rendez-vous a pris ce créneau au même instant (filet de sécurité de PostgreSQL).
            if ($e->getCode() === '23P01') {
                throw new SlotUnavailableException();
            }
            throw $e;
        }

        $booking = $this->bookings->find($id) ?? throw new BookingException('Réservation introuvable après création.');
        $this->notifications->sendForBooking($id);

        return new BookingResult($booking, $token, $quote);
    }

    /**
     * Changement de statut depuis l'administration.
     *
     * @throws BookingException
     */
    public function changeStatus(int $bookingId, string $to, ?int $userId, ?string $note = null): void
    {
        $booking = $this->bookings->find($bookingId) ?? throw new BookingException('Réservation introuvable.');
        $from = (string) $booking['status'];

        if (!BookingStatus::canTransition($from, $to)) {
            throw new BookingException(sprintf('Impossible de passer de « %s » à « %s ».', BookingStatus::label($from), BookingStatus::label($to)), 'status');
        }

        $this->db->transaction(function () use ($bookingId, $from, $to, $userId, $note): void {
            $this->bookings->updateStatus($bookingId, $to, $to === BookingStatus::CANCELLED ? $note : null);
            $this->bookings->addHistory($bookingId, $from, $to, $userId, $note);

            $event = match ($to) {
                BookingStatus::CONFIRMED => 'booking.confirmed',
                BookingStatus::REFUSED => 'booking.refused',
                BookingStatus::CANCELLED => 'booking.cancelled',
                // Invitation à laisser un avis (désactivable dans les paramètres).
                BookingStatus::COMPLETED => $this->settings->bool('notifications.review_request', true) ? 'booking.review_request' : null,
                default => null,
            };
            if ($event !== null) {
                $this->notify($event, $bookingId, $note !== null ? ['note' => $note] : []);
            }
        });

        $this->notifications->sendForBooking($bookingId);
    }

    /**
     * Déplace un rendez-vous (même prestation, même durée).
     *
     * @throws BookingException
     */
    public function reschedule(int $bookingId, DateTimeImmutable $newStart, ?int $userId, bool $ignoreSchedule = false): void
    {
        $booking = $this->bookings->find($bookingId) ?? throw new BookingException('Réservation introuvable.');
        if (!BookingStatus::isActive((string) $booking['status'])) {
            throw new BookingException('Seul un rendez-vous en attente, confirmé ou en cours peut être déplacé.', 'status');
        }

        $duration = (int) round(((new DateTimeImmutable((string) $booking['ends_at']))->getTimestamp() - (new DateTimeImmutable((string) $booking['starts_at']))->getTimestamp()) / 60);
        $start = $newStart->setTimezone($this->clock->zone());
        $end = $start->modify("+{$duration} minutes");

        try {
            $this->db->transaction(function () use ($booking, $bookingId, $start, $end, $duration, $userId, $ignoreSchedule): void {
                $this->lockDay($start);
                if (!$ignoreSchedule && !$this->availability->isAvailable($start, $duration, $bookingId)) {
                    throw new SlotUnavailableException('Ce créneau n\'est pas disponible.');
                }
                $this->bookings->reschedule($bookingId, $start->format(DATE_ATOM), $end->format(DATE_ATOM));
                $this->bookings->addHistory($bookingId, (string) $booking['status'], (string) $booking['status'], $userId,
                    'Déplacé au ' . $start->format('d/m/Y à H:i'));
                $this->notify('booking.rescheduled', $bookingId, ['previous_start' => (string) $booking['starts_at']]);
            });
        } catch (PDOException $e) {
            if ($e->getCode() === '23P01') {
                throw new SlotUnavailableException('Ce créneau chevauche un autre rendez-vous.');
            }
            throw $e;
        }

        $this->notifications->sendForBooking($bookingId);
    }

    /**
     * Annulation par la cliente (lien « gérer mon rendez-vous »), dans le délai autorisé.
     *
     * @param array<string, mixed> $booking
     * @throws BookingException
     */
    public function cancelByCustomer(array $booking, ?string $reason): void
    {
        if (!$this->canCustomerCancel($booking)) {
            throw new BookingException('Ce rendez-vous ne peut plus être annulé en ligne. Contactez-moi directement.');
        }

        $this->db->transaction(function () use ($booking, $reason): void {
            $note = 'Annulé par la cliente' . ($reason !== null && $reason !== '' ? ' : ' . $reason : '');
            $this->bookings->updateStatus((int) $booking['id'], BookingStatus::CANCELLED, $note);
            $this->bookings->addHistory((int) $booking['id'], (string) $booking['status'], BookingStatus::CANCELLED, null, $note);
            $this->notify('booking.cancelled_by_customer', (int) $booking['id']);
        });

        $this->notifications->sendForBooking((int) $booking['id']);
    }

    /** @param array<string, mixed> $booking */
    public function canCustomerCancel(array $booking): bool
    {
        if (!in_array($booking['status'], [BookingStatus::PENDING, BookingStatus::CONFIRMED], true)) {
            return false;
        }

        $hours = max(0, $this->settings->int('booking.cancellation_hours', 48));
        $deadline = (new DateTimeImmutable((string) $booking['starts_at']))->modify("-{$hours} hours");

        return $this->clock->now() <= $deadline;
    }

    public function setFinalTotal(int $bookingId, ?string $amount, ?int $userId): void
    {
        $this->db->transaction(function () use ($bookingId, $amount, $userId): void {
            $booking = $this->bookings->find($bookingId) ?? throw new BookingException('Réservation introuvable.');
            $this->bookings->setFinalTotal($bookingId, $amount);
            $this->bookings->addHistory($bookingId, (string) $booking['status'], (string) $booking['status'], $userId,
                $amount !== null ? 'Prix final : ' . number_format((float) $amount, 2, ',', ' ') . ' €' : 'Prix final retiré');
        });
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Verrou transactionnel PostgreSQL propre à une journée (libéré automatiquement au COMMIT/ROLLBACK). */
    private function lockDay(DateTimeImmutable $start): void
    {
        $this->db->execute("SELECT pg_advisory_xact_lock(hashtext('booking-day:' || :day))", ['day' => $start->format('Y-m-d')]);
    }

    private function newReference(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::REFERENCE_ALPHABET[random_int(0, strlen(self::REFERENCE_ALPHABET) - 1)];
            }
            $reference = 'HSG-' . $code;
        } while ($this->bookings->referenceExists($reference));

        return $reference;
    }
}
