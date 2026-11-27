<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use App\Core\Database;

/**
 * Horaires d'ouverture lisibles : jours consécutifs identiques regroupés
 * (« Jeudi – dimanche : 10 h – 19 h »), pauses et fermetures exceptionnelles à venir.
 */
final class OpeningHoursService
{
    public const DAYS = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<int, list<array{0: string, 1: string}>> jour ISO => plages [ouverture, fermeture] « HH:MM »
     */
    public function weekly(): array
    {
        $week = array_fill_keys(array_keys(self::DAYS), []);
        foreach ($this->db->fetchAll("SELECT weekday, to_char(opens_at, 'HH24:MI') AS o, to_char(closes_at, 'HH24:MI') AS c FROM opening_hours ORDER BY weekday, opens_at") as $row) {
            $week[(int) $row['weekday']][] = [(string) $row['o'], (string) $row['c']];
        }

        return $week;
    }

    /**
     * @param array<int, list<array{0: string, 1: string}>>|null $week
     * @return list<array{days: string, hours: string, closed: bool}>
     */
    public function summary(?array $week = null): array
    {
        $week ??= $this->weekly();
        $rows = [];
        $start = null;
        $previous = null;
        $previousDay = null;

        foreach ($week as $day => $ranges) {
            $label = $this->rangesLabel($ranges);
            if ($label !== $previous) {
                if ($previous !== null) {
                    $rows[] = $this->row($start, $previousDay, $previous);
                }
                $start = $day;
                $previous = $label;
            }
            $previousDay = $day;
        }
        if ($previous !== null) {
            $rows[] = $this->row($start, $previousDay, $previous);
        }

        return $rows;
    }

    /**
     * Fermetures exceptionnelles (congés…) qui commencent dans les $days prochains jours ou sont en cours.
     *
     * @return list<array{label: string, note: ?string}>
     */
    public function upcomingClosures(int $days = 45): array
    {
        $rows = $this->db->fetchAll(
            'SELECT starts_on, ends_on, note FROM blocked_dates
             WHERE ends_on >= current_date AND starts_on <= current_date + CAST(:days AS integer)
             ORDER BY starts_on',
            ['days' => $days],
        );

        return array_map(fn (array $r): array => [
            'label' => $r['starts_on'] === $r['ends_on']
                ? 'Fermé le ' . $this->date((string) $r['starts_on'])
                : 'Fermé du ' . $this->date((string) $r['starts_on']) . ' au ' . $this->date((string) $r['ends_on']),
            'note' => $r['note'] !== null ? (string) $r['note'] : null,
        ], $rows);
    }

    /** @param list<array{0: string, 1: string}> $ranges */
    private function rangesLabel(array $ranges): string
    {
        if ($ranges === []) {
            return 'Fermé';
        }

        return implode(' et ', array_map(fn (array $r): string => $this->time($r[0]) . ' – ' . $this->time($r[1]), $ranges));
    }

    /** @return array{days: string, hours: string, closed: bool} */
    private function row(int $from, int $to, string $hours): array
    {
        $days = match (true) {
            $from === $to => ucfirst(self::DAYS[$from]),
            $to === $from + 1 => ucfirst(self::DAYS[$from]) . ' et ' . self::DAYS[$to],
            default => ucfirst(self::DAYS[$from]) . ' au ' . self::DAYS[$to],
        };

        return ['days' => $days, 'hours' => $hours, 'closed' => $hours === 'Fermé'];
    }

    /** « 10:00 » → « 10 h », « 14:30 » → « 14 h 30 » */
    private function time(string $hhmm): string
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return $h . ' h' . ($m > 0 ? ' ' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) : '');
    }

    private function date(string $ymd): string
    {
        $months = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        [$y, $m, $d] = array_map('intval', explode('-', $ymd));
        $day = $d === 1 ? '1er' : (string) $d;

        return $day . ' ' . $months[$m] . ($y !== (int) date('Y') ? ' ' . $y : '');
    }
}
