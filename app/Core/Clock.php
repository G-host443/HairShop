<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Horloge de l'application. Injectée plutôt que d'appeler « new DateTime() » partout,
 * pour pouvoir figer le temps dans les tests (délais, horizon, changement d'heure).
 */
class Clock
{
    private ?DateTimeImmutable $frozen = null;

    public function __construct(private readonly string $timezone = 'Europe/Paris')
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->frozen ?? new DateTimeImmutable('now', $this->zone());
    }

    public function zone(): DateTimeZone
    {
        return new DateTimeZone($this->timezone);
    }

    /** Fige l'heure (tests uniquement). */
    public function freeze(DateTimeImmutable|string $moment): void
    {
        $this->frozen = is_string($moment) ? new DateTimeImmutable($moment, $this->zone()) : $moment->setTimezone($this->zone());
    }
}
