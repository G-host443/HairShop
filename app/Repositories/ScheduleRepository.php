<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Planning : horaires hebdomadaires, horaires exceptionnels, fermetures (congés) et créneaux bloqués.
 */
final class ScheduleRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    // ---------- Horaires hebdomadaires ----------

    /** @return array<int, list<array{0: string, 1: string}>> jour ISO (1-7) => plages « HH:MM » */
    public function weekly(): array
    {
        $week = array_fill(1, 7, []);
        foreach ($this->db->fetchAll("SELECT weekday, to_char(opens_at, 'HH24:MI') AS o, to_char(closes_at, 'HH24:MI') AS c FROM opening_hours ORDER BY weekday, opens_at") as $row) {
            $week[(int) $row['weekday']][] = [(string) $row['o'], (string) $row['c']];
        }

        return $week;
    }

    /** @param array<int, list<array{0: string, 1: string}>> $week remplace toute la semaine */
    public function replaceWeekly(array $week): void
    {
        $this->db->transaction(function () use ($week): void {
            $this->db->execute('DELETE FROM opening_hours');
            foreach ($week as $day => $ranges) {
                foreach ($ranges as [$opens, $closes]) {
                    $this->db->execute(
                        'INSERT INTO opening_hours (weekday, opens_at, closes_at) VALUES (:d, :o, :c)',
                        ['d' => $day, 'o' => $opens, 'c' => $closes],
                    );
                }
            }
        });
    }

    // ---------- Horaires exceptionnels ----------

    /** @return list<array{0: string, 1: string}> plages exceptionnelles d'une date (vide = horaires habituels) */
    public function specialRanges(string $date): array
    {
        return array_map(static fn (array $r): array => [(string) $r['o'], (string) $r['c']], $this->db->fetchAll(
            "SELECT to_char(opens_at, 'HH24:MI') AS o, to_char(closes_at, 'HH24:MI') AS c FROM special_hours WHERE date = CAST(:d AS date) ORDER BY opens_at",
            ['d' => $date],
        ));
    }

    /** @return list<array<string, mixed>> */
    public function upcomingSpecialHours(string $fromDate): array
    {
        return $this->db->fetchAll(
            "SELECT id, date, to_char(opens_at, 'HH24:MI') AS opens_at, to_char(closes_at, 'HH24:MI') AS closes_at, note
             FROM special_hours WHERE date >= CAST(:d AS date) ORDER BY date, opens_at",
            ['d' => $fromDate],
        );
    }

    public function addSpecialHours(string $date, string $opens, string $closes, ?string $note): void
    {
        $this->db->execute(
            'INSERT INTO special_hours (date, opens_at, closes_at, note) VALUES (CAST(:d AS date), :o, :c, :n)',
            ['d' => $date, 'o' => $opens, 'c' => $closes, 'n' => $note],
        );
    }

    public function specialOverlaps(string $date, string $opens, string $closes): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT EXISTS (SELECT 1 FROM special_hours WHERE date = CAST(:d AS date) AND timerange(opens_at, closes_at) && timerange(CAST(:o AS time), CAST(:c AS time)))',
            ['d' => $date, 'o' => $opens, 'c' => $closes],
        );
    }

    public function deleteSpecialHours(int $id): bool
    {
        return $this->db->execute('DELETE FROM special_hours WHERE id = :id', ['id' => $id]) === 1;
    }

    // ---------- Fermetures (congés) ----------

    public function isDateBlocked(string $date): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT EXISTS (SELECT 1 FROM blocked_dates WHERE CAST(:d AS date) BETWEEN starts_on AND ends_on)',
            ['d' => $date],
        );
    }

    /** @return list<string> dates « AAAA-MM-JJ » fermées entre deux dates incluses */
    public function blockedDatesBetween(string $from, string $to): array
    {
        return array_column($this->db->fetchAll(
            "SELECT to_char(d, 'YYYY-MM-DD') AS d
             FROM blocked_dates b, generate_series(GREATEST(b.starts_on, CAST(:f AS date)), LEAST(b.ends_on, CAST(:t AS date)), interval '1 day') AS d
             WHERE b.ends_on >= CAST(:f2 AS date) AND b.starts_on <= CAST(:t2 AS date)",
            ['f' => $from, 't' => $to, 'f2' => $from, 't2' => $to],
        ), 'd');
    }

    /** @return list<array<string, mixed>> fermetures en cours ou à venir */
    public function upcomingBlockedDates(string $fromDate): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM blocked_dates WHERE ends_on >= CAST(:d AS date) ORDER BY starts_on',
            ['d' => $fromDate],
        );
    }

    public function addBlockedDates(string $from, string $to, string $reason, ?string $note): void
    {
        $this->db->execute(
            'INSERT INTO blocked_dates (starts_on, ends_on, reason, note) VALUES (CAST(:f AS date), CAST(:t AS date), :r, :n)',
            ['f' => $from, 't' => $to, 'r' => $reason, 'n' => $note],
        );
    }

    public function deleteBlockedDates(int $id): bool
    {
        return $this->db->execute('DELETE FROM blocked_dates WHERE id = :id', ['id' => $id]) === 1;
    }

    // ---------- Créneaux bloqués ----------

    /** @return list<array<string, mixed>> */
    public function upcomingBlockedSlots(string $fromIso): array
    {
        return $this->db->fetchAll(
            'SELECT id, lower(period) AS starts_at, upper(period) AS ends_at, reason FROM blocked_slots
             WHERE upper(period) > CAST(:f AS timestamptz) ORDER BY lower(period)',
            ['f' => $fromIso],
        );
    }

    public function addBlockedSlot(string $startsIso, string $endsIso, ?string $reason, ?int $userId): void
    {
        $this->db->execute(
            "INSERT INTO blocked_slots (period, reason, created_by) VALUES (tstzrange(CAST(:s AS timestamptz), CAST(:e AS timestamptz), '[)'), :r, :u)",
            ['s' => $startsIso, 'e' => $endsIso, 'r' => $reason, 'u' => $userId],
        );
    }

    public function deleteBlockedSlot(int $id): bool
    {
        return $this->db->execute('DELETE FROM blocked_slots WHERE id = :id', ['id' => $id]) === 1;
    }

    // ---------- Occupation ----------

    /**
     * Intervalles occupés qui chevauchent une période : rendez-vous actifs et créneaux bloqués.
     *
     * @return list<array{0: string, 1: string, 2: string}> [début ISO, fin ISO, « booking »|« blocked »]
     */
    public function busyIntervals(string $fromIso, string $toIso, ?int $excludeBookingId = null): array
    {
        $rows = $this->db->fetchAll(
            "SELECT starts_at AS s, ends_at AS e, 'booking' AS kind FROM bookings
             WHERE status IN ('pending', 'confirmed', 'in_progress')
               AND during && tstzrange(CAST(:f AS timestamptz), CAST(:t AS timestamptz), '[)')
               AND id IS DISTINCT FROM CAST(:exclude AS bigint)
             UNION ALL
             SELECT lower(period), upper(period), 'blocked' FROM blocked_slots
             WHERE period && tstzrange(CAST(:f2 AS timestamptz), CAST(:t2 AS timestamptz), '[)')",
            ['f' => $fromIso, 't' => $toIso, 'f2' => $fromIso, 't2' => $toIso, 'exclude' => $excludeBookingId],
        );

        return array_map(static fn (array $r): array => [(string) $r['s'], (string) $r['e'], (string) $r['kind']], $rows);
    }

    /** Nombre de rendez-vous actifs sur une plage de dates (avertissement avant de fermer). */
    public function activeBookingsBetween(string $fromDate, string $toDate, string $timezone): int
    {
        return (int) $this->db->fetchValue(
            "SELECT count(*) FROM bookings
             WHERE status IN ('pending', 'confirmed', 'in_progress')
               AND (starts_at AT TIME ZONE :tz)::date BETWEEN CAST(:f AS date) AND CAST(:t AS date)",
            ['tz' => $timezone, 'f' => $fromDate, 't' => $toDate],
        );
    }
}
