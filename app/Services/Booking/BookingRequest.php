<?php

declare(strict_types=1);

namespace App\Services\Booking;

use DateTimeImmutable;

/**
 * Données d'une demande de réservation (site public ou saisie manuelle de l'administration).
 */
final class BookingRequest
{
    /**
     * @param list<int> $optionIds
     */
    public function __construct(
        public readonly int $serviceId,
        public readonly ?int $variantId,
        public readonly ?int $lengthId,
        public readonly array $optionIds,
        public readonly DateTimeImmutable $startsAt,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly ?string $comment = null,
        public readonly bool $rulesAccepted = false,
        public readonly bool $marketingConsent = false,
        public readonly string $source = 'web',
        public readonly ?int $createdBy = null,
        // Saisie manuelle uniquement :
        public readonly ?string $forcedStatus = null,
        public readonly bool $ignoreSchedule = false,
        // Cliente connectée : la réservation va sur la fiche de son compte.
        public readonly ?int $customerId = null,
    ) {
    }
}
