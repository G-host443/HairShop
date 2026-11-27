<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Core\Clock;
use App\Repositories\ScheduleRepository;
use App\Services\Settings\SettingsService;
use DateInterval;
use DateTimeImmutable;

/**
 * Calcul des créneaux disponibles.
 *
 * Pour une date et une durée :
 *   1. jour hors horizon de réservation, ou fermé (congés) → aucun créneau ;
 *   2. plages d'ouverture : horaires exceptionnels de la date s'il y en a, sinon horaires de la semaine ;
 *   3. départs candidats tous les N minutes (paramètre), le rendez-vous devant tenir entier dans une plage
 *      (pas de chevauchement d'une pause) ;
 *   4. on retire les départs trop proches (délai minimum) et ceux qui chevauchent un rendez-vous actif
 *      ou un créneau bloqué, en respectant le temps de battement entre deux rendez-vous.
 *
 * Les heures sont calculées en heure locale (fuseau des paramètres) : les jours de changement d'heure sont gérés.
 * La protection finale contre les doubles réservations reste la contrainte PostgreSQL (étape 7).
 */
final class AvailabilityService
{
    public function __construct(
        private readonly ScheduleRepository $schedule,
        private readonly SettingsService $settings,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<DateTimeImmutable> heures de début possibles, dans l'ordre
     */
    public function slotsForDate(string $date, int $durationMinutes, ?int $excludeBookingId = null): array
    {
        $day = $this->parseDate($date);
        if ($day === null || $durationMinutes < 1 || !$this->isWithinHorizon($day) || $this->schedule->isDateBlocked($day->format('Y-m-d'))) {
            return [];
        }

        $ranges = $this->rangesFor($day);
        if ($ranges === []) {
            return [];
        }

        $interval = max(5, $this->settings->int('booking.slot_interval_minutes', 30));
        $buffer = max(0, $this->settings->int('booking.buffer_minutes', 0));
        $earliest = $this->clock->now()->add(new DateInterval('PT' . max(0, $this->settings->int('booking.min_notice_hours', 0)) . 'H'));

        // Occupation de la journée (élargie du battement de part et d'autre).
        $dayStart = $day->setTime(0, 0);
        $dayEnd = $dayStart->modify('+1 day');
        $busy = [];
        foreach ($this->schedule->busyIntervals($dayStart->format(DATE_ATOM), $dayEnd->format(DATE_ATOM), $excludeBookingId) as [$start, $end, $kind]) {
            $pad = $kind === 'booking' ? $buffer : 0;
            $busy[] = [
                (new DateTimeImmutable($start))->modify("-{$pad} minutes")->getTimestamp(),
                (new DateTimeImmutable($end))->modify("+{$pad} minutes")->getTimestamp(),
            ];
        }

        $slots = [];
        foreach ($ranges as [$opens, $closes]) {
            $rangeStart = $this->at($day, $opens);
            $rangeEnd = $this->at($day, $closes);

            for ($start = $rangeStart; ; $start = $start->modify("+{$interval} minutes")) {
                $end = $start->modify("+{$durationMinutes} minutes");
                if ($end > $rangeEnd) {
                    break;
                }
                if ($start < $earliest || $this->overlaps($start->getTimestamp(), $end->getTimestamp(), $busy)) {
                    continue;
                }
                $slots[] = $start;
            }
        }

        return $slots;
    }

    /**
     * Le créneau exact est-il libre ? (Vérification au moment de réserver, étape 7.)
     */
    public function isAvailable(DateTimeImmutable $start, int $durationMinutes, ?int $excludeBookingId = null): bool
    {
        $local = $start->setTimezone($this->clock->zone());
        foreach ($this->slotsForDate($local->format('Y-m-d'), $durationMinutes, $excludeBookingId) as $slot) {
            if ($slot->getTimestamp() === $local->getTimestamp()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Jours ayant au moins un créneau pour cette durée, entre deux dates incluses (calendrier du mois).
     *
     * @return list<string> dates « AAAA-MM-JJ »
     */
    public function availableDates(string $from, string $to, int $durationMinutes): array
    {
        $start = $this->parseDate($from);
        $end = $this->parseDate($to);
        if ($start === null || $end === null || $end < $start) {
            return [];
        }

        // Pas plus de 62 jours par appel (un mois affiché, marges comprises).
        $end = min($end, $start->modify('+61 days'));
        $blocked = array_flip($this->schedule->blockedDatesBetween($start->format('Y-m-d'), $end->format('Y-m-d')));

        $dates = [];
        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            if (!isset($blocked[$key]) && $this->slotsForDate($key, $durationMinutes) !== []) {
                $dates[] = $key;
            }
        }

        return $dates;
    }

    /** Premier et dernier jour réservables. @return array{0: string, 1: string} */
    public function bookingWindow(): array
    {
        $today = $this->clock->now()->setTime(0, 0);
        $horizon = max(1, $this->settings->int('booking.horizon_days', 60));

        return [$today->format('Y-m-d'), $today->modify("+{$horizon} days")->format('Y-m-d')];
    }

    /** @return list<array{0: string, 1: string}> */
    private function rangesFor(DateTimeImmutable $day): array
    {
        $special = $this->schedule->specialRanges($day->format('Y-m-d'));
        if ($special !== []) {
            return $special;
        }

        // Semaine type mémorisée pendant la requête (le calcul d'un mois l'interroge pour chaque jour).
        $this->weekly ??= $this->schedule->weekly();

        return $this->weekly[(int) $day->format('N')] ?? [];
    }

    /** @var array<int, list<array{0: string, 1: string}>>|null */
    private ?array $weekly = null;

    private function isWithinHorizon(DateTimeImmutable $day): bool
    {
        [$first, $last] = $this->bookingWindow();
        $key = $day->format('Y-m-d');

        return $key >= $first && $key <= $last;
    }

    private function parseDate(string $date): ?DateTimeImmutable
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $this->clock->zone());

        return $day !== false && $day->format('Y-m-d') === $date ? $day : null;
    }

    /** Heure locale « HH:MM » d'un jour donné (DateTime gère seul le changement d'heure). */
    private function at(DateTimeImmutable $day, string $hhmm): DateTimeImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return $day->setTime($h, $m);
    }

    /** @param list<array{0: int, 1: int}> $busy */
    private function overlaps(int $start, int $end, array $busy): bool
    {
        foreach ($busy as [$busyStart, $busyEnd]) {
            if ($start < $busyEnd && $busyStart < $end) {
                return true;
            }
        }

        return false;
    }
}
