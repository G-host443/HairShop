<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Services\Catalog\Quote;

/**
 * Réservation créée. Le jeton du lien « gérer mon rendez-vous » n'existe en clair qu'ici :
 * seule son empreinte est stockée en base.
 */
final class BookingResult
{
    /** @param array<string, mixed> $booking */
    public function __construct(
        public readonly array $booking,
        public readonly string $manageToken,
        public readonly Quote $quote,
    ) {
    }
}
