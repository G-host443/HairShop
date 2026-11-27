<?php

declare(strict_types=1);

namespace App\Services\Booking;

/** Le créneau demandé n'est (plus) disponible. */
final class SlotUnavailableException extends BookingException
{
    public function __construct(string $message = 'Ce créneau vient d\'être réservé ou n\'est plus disponible. Choisissez-en un autre.')
    {
        parent::__construct($message, 'time');
    }
}
