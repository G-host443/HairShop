<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Core\Clock;
use App\Core\Database;
use DateTimeImmutable;

/**
 * Indicateurs et séries du tableau de bord, calculés à la demande dans PostgreSQL (jours au fuseau du salon).
 *
 * Chiffre d'affaires :
 * - réalisé : rendez-vous terminés, prix final s'il est saisi, sinon prix estimé ;
 * - prévu : rendez-vous en attente ou confirmés (prix estimé ; les prix sur devis ne sont pas comptés).
 */
final class DashboardService
{
    public const ACTIVE = "('pending', 'confirmed', 'in_progress')";

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /** @return array<string, int|float> */
    public function kpis(): array
    {
        $now = $this->clock->now();
        $params = [
            'tz' => $this->clock->zone()->getName(),
            'today' => $now->format('Y-m-d'),
            'month' => $now->format('Y-m-01'),
            'next_month' => $now->modify('first day of next month')->format('Y-m-d'),
            'now' => $now->format(DATE_ATOM),
        ];

        $row = $this->db->fetchOne(
            "SELECT
                (SELECT count(*) FROM bookings WHERE (starts_at AT TIME ZONE :tz)::date = CAST(:today AS date) AND status IN " . self::ACTIVE . ") AS today,
                (SELECT count(*) FROM bookings WHERE status = 'pending') AS pending,
                (SELECT count(*) FROM bookings WHERE status = 'confirmed' AND starts_at >= CAST(:now AS timestamptz)) AS upcoming,
                (SELECT count(*) FROM bookings) AS total_bookings,
                (SELECT count(*) FROM bookings WHERE (created_at AT TIME ZONE :tz2)::date >= CAST(:month AS date)) AS bookings_this_month,
                (SELECT COALESCE(sum(COALESCE(final_total, estimated_total)), 0) FROM bookings
                  WHERE status = 'completed' AND (starts_at AT TIME ZONE :tz3)::date >= CAST(:month2 AS date) AND (starts_at AT TIME ZONE :tz4)::date < CAST(:next_month AS date)) AS revenue_done,
                (SELECT COALESCE(sum(estimated_total), 0) FROM bookings
                  WHERE status IN ('pending', 'confirmed', 'in_progress') AND starts_at >= CAST(:now2 AS timestamptz) AND (starts_at AT TIME ZONE :tz5)::date < CAST(:next_month2 AS date)) AS revenue_expected,
                (SELECT count(*) FROM customers) AS customers,
                (SELECT count(*) FROM customers WHERE (created_at AT TIME ZONE :tz6)::date >= CAST(:month3 AS date)) AS new_customers,
                (SELECT count(*) FROM contact_messages WHERE NOT is_read) AS unread_messages,
                (SELECT count(*) FROM reviews WHERE status = 'pending') AS pending_reviews,
                (SELECT COALESCE(sum(views), 0) FROM site_visits WHERE day > CAST(:today2 AS date) - 30) AS views_30d",
            [
                'tz' => $params['tz'], 'tz2' => $params['tz'], 'tz3' => $params['tz'], 'tz4' => $params['tz'], 'tz5' => $params['tz'], 'tz6' => $params['tz'],
                'today' => $params['today'], 'today2' => $params['today'],
                'month' => $params['month'], 'month2' => $params['month'], 'month3' => $params['month'],
                'next_month' => $params['next_month'], 'next_month2' => $params['next_month'],
                'now' => $params['now'], 'now2' => $params['now'],
            ],
        ) ?? [];

        $kpis = [];
        foreach ($row as $key => $value) {
            $kpis[$key] = str_starts_with($key, 'revenue') ? (float) $value : (int) $value;
        }

        return $kpis;
    }

    /**
     * Rendez-vous par semaine (date du rendez-vous) sur les N dernières semaines et les 4 prochaines.
     *
     * @return list<array{week: string, label: string, done: int, upcoming: int, cancelled: int}>
     */
    public function bookingsPerWeek(int $past = 11, int $future = 4): array
    {
        $monday = $this->clock->now()->modify('monday this week')->setTime(0, 0);
        $start = $monday->modify("-{$past} weeks");
        $end = $monday->modify('+' . ($future + 1) . ' weeks');

        $rows = [];
        foreach ($this->db->fetchAll(
            "SELECT date_trunc('week', starts_at AT TIME ZONE :tz)::date AS week,
                    count(*) FILTER (WHERE status IN ('completed', 'confirmed', 'in_progress', 'pending')) AS active,
                    count(*) FILTER (WHERE status IN ('completed')) AS done,
                    count(*) FILTER (WHERE status IN ('cancelled', 'refused', 'no_show')) AS cancelled
             FROM bookings
             WHERE (starts_at AT TIME ZONE :tz2) >= CAST(:start AS timestamp) AND (starts_at AT TIME ZONE :tz3) < CAST(:end AS timestamp)
             GROUP BY 1",
            ['tz' => $this->tz(), 'tz2' => $this->tz(), 'tz3' => $this->tz(), 'start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')],
        ) as $row) {
            $rows[(string) $row['week']] = $row;
        }

        $series = [];
        for ($week = $start; $week < $end; $week = $week->modify('+1 week')) {
            $key = $week->format('Y-m-d');
            $row = $rows[$key] ?? ['active' => 0, 'done' => 0, 'cancelled' => 0];
            $series[] = [
                'week' => $key,
                'label' => $week->format('d/m'),
                'done' => (int) $row['done'],
                'upcoming' => (int) $row['active'] - (int) $row['done'],
                'cancelled' => (int) $row['cancelled'],
            ];
        }

        return $series;
    }

    /**
     * Chiffre d'affaires par mois : réalisé (terminés) et prévu (à venir), sur 6 mois passés et 2 à venir.
     *
     * @return list<array{month: string, label: string, done: float, expected: float}>
     */
    public function revenuePerMonth(int $past = 5, int $future = 2): array
    {
        $first = $this->clock->now()->modify('first day of this month')->setTime(0, 0);
        $start = $first->modify("-{$past} months");
        $end = $first->modify('+' . ($future + 1) . ' months');

        $rows = [];
        foreach ($this->db->fetchAll(
            "SELECT date_trunc('month', starts_at AT TIME ZONE :tz)::date AS month,
                    COALESCE(sum(COALESCE(final_total, estimated_total)) FILTER (WHERE status = 'completed'), 0) AS done,
                    COALESCE(sum(estimated_total) FILTER (WHERE status IN ('pending', 'confirmed', 'in_progress')), 0) AS expected
             FROM bookings
             WHERE (starts_at AT TIME ZONE :tz2) >= CAST(:start AS timestamp) AND (starts_at AT TIME ZONE :tz3) < CAST(:end AS timestamp)
             GROUP BY 1",
            ['tz' => $this->tz(), 'tz2' => $this->tz(), 'tz3' => $this->tz(), 'start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')],
        ) as $row) {
            $rows[(string) $row['month']] = $row;
        }

        $formatter = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $this->clock->zone(), null, 'MMM yyyy');
        $series = [];
        for ($month = $start; $month < $end; $month = $month->modify('+1 month')) {
            $key = $month->format('Y-m-d');
            $series[] = [
                'month' => $key,
                'label' => (string) $formatter->format($month),
                'done' => round((float) ($rows[$key]['done'] ?? 0), 2),
                'expected' => round((float) ($rows[$key]['expected'] ?? 0), 2),
            ];
        }

        return $series;
    }

    /**
     * Prestations les plus réservées (rendez-vous non annulés), du jour J−N aux rendez-vous déjà pris pour la suite.
     *
     * @return list<array{name: string, count: int, revenue: float}>
     */
    public function popularServices(int $days = 90, int $limit = 6): array
    {
        return array_map(static fn (array $r): array => ['name' => (string) $r['name'], 'count' => (int) $r['n'], 'revenue' => round((float) $r['revenue'], 2)],
            $this->db->fetchAll(
                "SELECT s.name, count(*) AS n, COALESCE(sum(COALESCE(b.final_total, b.estimated_total)), 0) AS revenue
                 FROM bookings b JOIN services s ON s.id = b.service_id
                 WHERE b.starts_at >= CAST(:now AS timestamptz) - make_interval(days => :days)
                   AND b.status NOT IN ('cancelled', 'refused')
                 GROUP BY s.id, s.name ORDER BY n DESC, s.name LIMIT :limit",
                ['now' => $this->clock->now()->format(DATE_ATOM), 'days' => $days, 'limit' => $limit],
            ));
    }

    /** @return list<array{day: string, label: string, views: int}> pages vues par jour (30 derniers jours) */
    public function visitsPerDay(int $days = 30): array
    {
        $today = $this->clock->now()->setTime(0, 0);
        $start = $today->modify('-' . ($days - 1) . ' days');
        $rows = [];
        foreach ($this->db->fetchAll(
            'SELECT day, sum(views) AS views FROM site_visits WHERE day >= CAST(:start AS date) GROUP BY day',
            ['start' => $start->format('Y-m-d')],
        ) as $row) {
            $rows[(string) $row['day']] = (int) $row['views'];
        }

        $series = [];
        for ($day = $start; $day <= $today; $day = $day->modify('+1 day')) {
            $series[] = ['day' => $day->format('Y-m-d'), 'label' => $day->format('d/m'), 'views' => $rows[$day->format('Y-m-d')] ?? 0];
        }

        return $series;
    }

    /** @return list<array{path: string, views: int}> pages les plus vues (30 derniers jours) */
    public function topPages(int $days = 30, int $limit = 8): array
    {
        return array_map(static fn (array $r): array => ['path' => (string) $r['path'], 'views' => (int) $r['views']],
            $this->db->fetchAll(
                'SELECT path, sum(views) AS views FROM site_visits WHERE day > CAST(:today AS date) - CAST(:days AS integer) GROUP BY path ORDER BY views DESC, path LIMIT :limit',
                ['today' => $this->clock->now()->format('Y-m-d'), 'days' => $days, 'limit' => $limit],
            ));
    }

    /** @return list<array<string, mixed>> rendez-vous du jour (actifs et terminés), dans l'ordre */
    public function today(): array
    {
        return $this->db->fetchAll(
            "SELECT b.id, b.reference, b.service_label, b.starts_at, b.ends_at, b.status, b.estimated_total, b.final_total,
                    c.first_name, c.last_name, c.phone, st.label AS status_label, st.color AS status_color
             FROM bookings b JOIN customers c ON c.id = b.customer_id JOIN booking_statuses st ON st.code = b.status
             WHERE (b.starts_at AT TIME ZONE :tz)::date = CAST(:today AS date) AND b.status NOT IN ('cancelled', 'refused')
             ORDER BY b.starts_at",
            ['tz' => $this->tz(), 'today' => $this->clock->now()->format('Y-m-d')],
        );
    }

    /** @return list<array<string, mixed>> demandes en attente de confirmation, les plus proches d'abord */
    public function pendingBookings(int $limit = 6): array
    {
        return $this->db->fetchAll(
            "SELECT b.id, b.reference, b.service_label, b.starts_at, b.created_at, c.first_name, c.last_name
             FROM bookings b JOIN customers c ON c.id = b.customer_id
             WHERE b.status = 'pending' ORDER BY b.starts_at LIMIT :limit",
            ['limit' => $limit],
        );
    }

    public function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    private function tz(): string
    {
        return $this->clock->zone()->getName();
    }
}
