<?php

declare(strict_types=1);

namespace App\Services\Booking;

/**
 * Statuts d'une réservation et transitions autorisées.
 */
final class BookingStatus
{
    public const PENDING = 'pending';
    public const CONFIRMED = 'confirmed';
    public const IN_PROGRESS = 'in_progress';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';
    public const REFUSED = 'refused';
    public const NO_SHOW = 'no_show';

    /** Statuts qui occupent le créneau (doivent correspondre à la contrainte bookings_no_overlap). */
    public const BLOCKING = [self::PENDING, self::CONFIRMED, self::IN_PROGRESS];

    public const LABELS = [
        self::PENDING => 'En attente',
        self::CONFIRMED => 'Confirmée',
        self::IN_PROGRESS => 'En cours',
        self::COMPLETED => 'Terminée',
        self::CANCELLED => 'Annulée',
        self::REFUSED => 'Refusée',
        self::NO_SHOW => 'Absence de la cliente',
    ];

    /** Libellé de l'action qui mène à chaque statut (boutons de l'administration). */
    public const ACTIONS = [
        self::CONFIRMED => 'Confirmer',
        self::IN_PROGRESS => 'Commencer',
        self::COMPLETED => 'Marquer terminée',
        self::CANCELLED => 'Annuler',
        self::REFUSED => 'Refuser',
        self::NO_SHOW => 'Absence',
    ];

    private const TRANSITIONS = [
        self::PENDING => [self::CONFIRMED, self::REFUSED, self::CANCELLED],
        self::CONFIRMED => [self::IN_PROGRESS, self::COMPLETED, self::NO_SHOW, self::CANCELLED],
        self::IN_PROGRESS => [self::COMPLETED, self::CANCELLED],
        self::COMPLETED => [],
        self::CANCELLED => [],
        self::REFUSED => [],
        self::NO_SHOW => [],
    ];

    /** @return list<string> */
    public static function nextStatuses(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::nextStatuses($from), true);
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public static function isActive(string $status): bool
    {
        return in_array($status, self::BLOCKING, true);
    }
}
