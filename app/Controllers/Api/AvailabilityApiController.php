<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Booking\AvailabilityService;
use App\Services\Catalog\PricingException;
use App\Services\Catalog\PricingService;

/**
 * GET /api/disponibilites
 *
 *   ?service=12&variant=34&length=5&options[]=7&date=2027-03-10   → créneaux du jour
 *   ?service=12&variant=34&length=5&mois=2027-03                  → jours disponibles du mois
 *
 * La durée est toujours recalculée côté serveur à partir de la prestation configurée.
 */
final class AvailabilityApiController
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly PricingService $pricing,
    ) {
    }

    public function index(Request $request): Response
    {
        $serviceId = self::int($request->query('service'));
        if ($serviceId === null) {
            return self::error('Paramètre « service » manquant.');
        }

        try {
            $quote = $this->pricing->quote(
                $serviceId,
                self::int($request->query('variant')),
                self::int($request->query('length')),
                array_values(array_filter(array_map([self::class, 'int'], (array) $request->query('options', [])))),
            );
        } catch (PricingException $e) {
            return self::error($e->getMessage());
        }

        $duration = $quote->durationMinutes;
        [$first, $last] = $this->availability->bookingWindow();

        $month = $request->query('mois');
        if (is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $from = max($first, $month . '-01');
            $to = min($last, date('Y-m-t', (int) strtotime($month . '-01')));

            return self::ok([
                'month' => $month,
                'duration_minutes' => $duration,
                'window' => ['first' => $first, 'last' => $last],
                'dates' => $from <= $to ? $this->availability->availableDates($from, $to, $duration) : [],
            ]);
        }

        $date = $request->query('date');
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return self::error('Paramètre « date » (AAAA-MM-JJ) ou « mois » (AAAA-MM) manquant.');
        }

        $slots = $this->availability->slotsForDate($date, $duration);

        return self::ok([
            'date' => $date,
            'duration_minutes' => $duration,
            'window' => ['first' => $first, 'last' => $last],
            'slots' => array_map(static fn (\DateTimeImmutable $s): array => [
                'time' => $s->format('H:i'),
                'starts_at' => $s->format(DATE_ATOM),
                'ends_at' => $s->modify("+{$duration} minutes")->format('H:i'),
            ], $slots),
        ]);
    }

    private static function int(mixed $value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $int === false ? null : $int;
    }

    /** @param array<string, mixed> $data */
    private static function ok(array $data): Response
    {
        return Response::json($data)->withHeader('Cache-Control', 'no-store');
    }

    private static function error(string $message): Response
    {
        return Response::json(['error' => ['status' => 422, 'message' => $message]], 422)->withHeader('Cache-Control', 'no-store');
    }
}
