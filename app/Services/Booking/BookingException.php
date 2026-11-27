<?php

declare(strict_types=1);

namespace App\Services\Booking;

use RuntimeException;

/**
 * Réservation impossible, avec un message affichable tel quel.
 * $field indique le champ du formulaire concerné (pour l'afficher au bon endroit).
 */
class BookingException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field = 'booking')
    {
        parent::__construct($message);
    }
}
