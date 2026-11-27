<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Clock;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Router;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View;
use App\Models\User;
use App\Repositories\ScheduleRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Booking\AvailabilityService;
use App\Services\Schedule\OpeningHoursService;
use DateTimeImmutable;

/**
 * Horaires : semaine type (avec pauses), horaires exceptionnels, fermetures, créneaux bloqués,
 * et aperçu des créneaux proposés aux clientes.
 */
final class ScheduleController extends Controller
{
    private const MAX_RANGES_PER_DAY = 3;

    public function __construct(
        View $view,
        Router $router,
        Session $session,
        private readonly ScheduleRepository $schedule,
        private readonly AvailabilityService $availability,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {
        parent::__construct($view, $router, $session);
    }

    public function index(Request $request): Response
    {
        $now = $this->clock->now();
        $today = $now->format('Y-m-d');

        // Aperçu : créneaux d'une date pour une durée donnée.
        $previewDate = (string) $request->query('apercu_date', $today);
        $previewDuration = (int) $request->query('apercu_duree', 180);
        $previewDuration = max(15, min(1440, $previewDuration));
        $preview = DateTimeImmutable::createFromFormat('!Y-m-d', $previewDate) !== false
            ? $this->availability->slotsForDate($previewDate, $previewDuration)
            : [];

        return $this->render('admin/schedule/index.twig', [
            'week' => $this->schedule->weekly(),
            'days' => OpeningHoursService::DAYS,
            'max_ranges' => self::MAX_RANGES_PER_DAY,
            'special_hours' => $this->schedule->upcomingSpecialHours($today),
            'blocked_dates' => $this->schedule->upcomingBlockedDates($today),
            'blocked_slots' => $this->schedule->upcomingBlockedSlots($now->format(DATE_ATOM)),
            'today' => $today,
            'preview' => ['date' => $previewDate, 'duration' => $previewDuration, 'slots' => $preview],
            'window' => $this->availability->bookingWindow(),
            'timezone' => $this->clock->zone()->getName(),
        ]);
    }

    public function updateWeekly(Request $request): Response
    {
        $input = (array) $request->input('hours', []);
        $week = [];
        $errors = [];

        foreach (OpeningHoursService::DAYS as $day => $label) {
            $ranges = [];
            for ($i = 0; $i < self::MAX_RANGES_PER_DAY; $i++) {
                $opens = trim((string) ($input[$day][$i]['opens'] ?? ''));
                $closes = trim((string) ($input[$day][$i]['closes'] ?? ''));
                if ($opens === '' && $closes === '') {
                    continue;
                }
                if (!self::isTime($opens) || !self::isTime($closes)) {
                    $errors["hours.{$day}"] = ucfirst($label) . ' : indiquez une heure d\'ouverture et de fermeture.';
                    continue 2;
                }
                if ($closes <= $opens) {
                    $errors["hours.{$day}"] = ucfirst($label) . " : la fermeture ({$closes}) doit suivre l'ouverture ({$opens}).";
                    continue 2;
                }
                $ranges[] = [$opens, $closes];
            }

            usort($ranges, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
            for ($i = 1; $i < count($ranges); $i++) {
                if ($ranges[$i][0] < $ranges[$i - 1][1]) {
                    $errors["hours.{$day}"] = ucfirst($label) . ' : les plages horaires se chevauchent.';
                    continue 2;
                }
            }
            $week[$day] = $ranges;
        }

        if ($errors !== []) {
            throw new ValidationException(array_map(static fn (string $m): array => [$m], $errors));
        }

        $this->schedule->replaceWeekly($week);
        $this->audit->log('schedule.weekly_updated', $this->userId($request), $request, null, null, ['week' => $week]);
        $this->flash('success', 'Horaires de la semaine enregistrés.');

        return $this->redirectTo('admin.schedule');
    }

    public function storeSpecialHours(Request $request): Response
    {
        $data = $this->validate($request->all(), [
            'special_date' => 'required|date',
            'special_opens' => 'required|regex:/^\d{2}:\d{2}$/',
            'special_closes' => 'required|regex:/^\d{2}:\d{2}$/',
            'special_note' => 'nullable|max:200',
        ], ['special_date' => 'date', 'special_opens' => 'ouverture', 'special_closes' => 'fermeture', 'special_note' => 'note']);

        $date = $this->dateOrFail((string) $data['special_date'], 'special_date');
        if ((string) $data['special_closes'] <= (string) $data['special_opens']) {
            throw ValidationException::withMessages(['special_closes' => 'La fermeture doit suivre l\'ouverture.']);
        }
        if ($this->schedule->specialOverlaps($date, (string) $data['special_opens'], (string) $data['special_closes'])) {
            throw ValidationException::withMessages(['special_opens' => 'Cette plage chevauche une plage déjà saisie pour cette date.']);
        }

        $this->schedule->addSpecialHours($date, (string) $data['special_opens'], (string) $data['special_closes'], self::nullable($data['special_note'] ?? null));
        $this->flash('success', 'Horaire exceptionnel ajouté : il remplace les horaires habituels de ce jour.');

        return $this->redirectTo('admin.schedule');
    }

    public function destroySpecialHours(int $id): Response
    {
        $this->schedule->deleteSpecialHours($id);
        $this->flash('success', 'Horaire exceptionnel supprimé.');

        return $this->redirectTo('admin.schedule');
    }

    public function storeBlockedDates(Request $request): Response
    {
        $data = $this->validate($request->all(), [
            'closure_from' => 'required|date',
            'closure_to' => 'nullable|date',
            'closure_reason' => 'required|in:holiday,closure,other',
            'closure_note' => 'nullable|max:200',
        ], ['closure_from' => 'premier jour', 'closure_to' => 'dernier jour', 'closure_reason' => 'motif', 'closure_note' => 'note']);

        $from = $this->dateOrFail((string) $data['closure_from'], 'closure_from');
        $to = ($data['closure_to'] ?? '') !== '' ? $this->dateOrFail((string) $data['closure_to'], 'closure_to') : $from;
        if ($to < $from) {
            throw ValidationException::withMessages(['closure_to' => 'Le dernier jour doit être après le premier.']);
        }

        $this->schedule->addBlockedDates($from, $to, (string) $data['closure_reason'], self::nullable($data['closure_note'] ?? null));
        $this->audit->log('schedule.closure_added', $this->userId($request), $request, null, null, ['from' => $from, 'to' => $to]);

        $existing = $this->schedule->activeBookingsBetween($from, $to, $this->clock->zone()->getName());
        $this->flash('success', 'Fermeture enregistrée : aucun créneau ne sera proposé sur cette période.');
        if ($existing > 0) {
            $this->flash('warning', "Attention : {$existing} rendez-vous déjà pris sur cette période. Ils ne sont pas annulés automatiquement : pensez à prévenir les clientes.");
        }

        return $this->redirectTo('admin.schedule');
    }

    public function destroyBlockedDates(int $id): Response
    {
        $this->schedule->deleteBlockedDates($id);
        $this->flash('success', 'Fermeture supprimée.');

        return $this->redirectTo('admin.schedule');
    }

    public function storeBlockedSlot(Request $request): Response
    {
        $data = $this->validate($request->all(), [
            'slot_date' => 'required|date',
            'slot_from' => 'required|regex:/^\d{2}:\d{2}$/',
            'slot_to' => 'required|regex:/^\d{2}:\d{2}$/',
            'slot_reason' => 'nullable|max:200',
        ], ['slot_date' => 'date', 'slot_from' => 'début', 'slot_to' => 'fin', 'slot_reason' => 'motif']);

        $date = $this->dateOrFail((string) $data['slot_date'], 'slot_date');
        $start = new DateTimeImmutable("{$date} {$data['slot_from']}", $this->clock->zone());
        $end = new DateTimeImmutable("{$date} {$data['slot_to']}", $this->clock->zone());
        if ($end <= $start) {
            throw ValidationException::withMessages(['slot_to' => 'La fin doit suivre le début.']);
        }

        $this->schedule->addBlockedSlot($start->format(DATE_ATOM), $end->format(DATE_ATOM), self::nullable($data['slot_reason'] ?? null), $this->userId($request));
        $this->flash('success', 'Créneau bloqué : il ne sera plus proposé.');

        return $this->redirectTo('admin.schedule');
    }

    public function destroyBlockedSlot(int $id): Response
    {
        $this->schedule->deleteBlockedSlot($id);
        $this->flash('success', 'Créneau débloqué.');

        return $this->redirectTo('admin.schedule');
    }

    private function dateOrFail(string $value, string $field): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([$field => 'Date invalide (format attendu : AAAA-MM-JJ).']);
        }

        return $value;
    }

    private static function isTime(string $value): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    private static function nullable(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }

    private function userId(Request $request): ?int
    {
        $user = $request->attribute('user');

        return $user instanceof User ? $user->id : null;
    }
}
