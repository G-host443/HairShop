<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Clock;
use App\Core\Exceptions\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Router;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View;
use App\Helpers\Slug;
use App\Models\User;
use App\Repositories\BookingRepository;
use App\Repositories\CustomerAdminRepository;
use App\Repositories\NotificationRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\AuthService;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingRequest;
use App\Services\Booking\BookingService;
use App\Services\Booking\BookingStatus;
use App\Services\Catalog\CatalogReader;
use DateTimeImmutable;

/**
 * Rendez-vous : liste, calendrier, fiche, changements de statut, déplacement, prix final,
 * saisie manuelle et export CSV.
 */
final class BookingAdminController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        View $view,
        Router $router,
        Session $session,
        private readonly BookingRepository $bookings,
        private readonly BookingService $service,
        private readonly AvailabilityService $availability,
        private readonly CatalogReader $catalog,
        private readonly AuthService $auth,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly NotificationRepository $notificationLog,
        private readonly CustomerAdminRepository $customers,
    ) {
        parent::__construct($view, $router, $session);
    }

    // ---------- Liste ----------

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $page = max(1, (int) $request->query('page', 1));
        $result = $this->bookings->search($filters, $this->tz(), $page, self::PER_PAGE);

        return $this->render('admin/bookings/index.twig', [
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'filters' => $filters,
            'statuses' => BookingStatus::LABELS,
            'services' => $this->serviceOptions(),
            // Paramètres d'adresse à conserver (pagination, export).
            'query' => array_filter([
                'periode' => $request->query('periode'),
                'du' => $request->query('du'),
                'au' => $request->query('au'),
                'statut' => $filters['status'],
                'prestation' => $filters['service'],
                'q' => $filters['q'],
            ], static fn ($v) => is_scalar($v) && $v !== ''),
            'can_manage' => $this->auth->can('bookings.manage'),
        ]);
    }

    public function export(Request $request): Response
    {
        $filters = $this->filters($request);
        $rows = $this->bookings->search($filters, $this->tz(), 1, 5000)['items'];

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // BOM : accents corrects à l'ouverture dans Excel
        fputcsv($out, ['Référence', 'Date', 'Début', 'Fin', 'Statut', 'Prestation', 'Prénom', 'Nom', 'E-mail', 'Téléphone', 'Prix estimé', 'Prix final', 'Source', 'Commentaire'], ';', '"', '');
        foreach ($rows as $r) {
            $start = (new DateTimeImmutable((string) $r['starts_at']))->setTimezone($this->clock->zone());
            $end = (new DateTimeImmutable((string) $r['ends_at']))->setTimezone($this->clock->zone());
            fputcsv($out, array_map([self::class, 'csvCell'], [
                $r['reference'], $start->format('d/m/Y'), $start->format('H:i'), $end->format('H:i'), $r['status_label'], $r['service_label'],
                $r['first_name'], $r['last_name'], $r['email'], $r['phone'],
                $r['estimated_total'] !== null ? str_replace('.', ',', (string) $r['estimated_total']) : 'devis',
                $r['final_total'] !== null ? str_replace('.', ',', (string) $r['final_total']) : '',
                $r['source'], $r['customer_comment'],
            ]), ';', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);

        $this->audit->log('bookings.exported', $this->userId($request), $request, null, null, ['count' => count($rows)]);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="rendez-vous-' . $this->clock->now()->format('Y-m-d') . '.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }

    // ---------- Calendrier ----------

    public function calendar(Request $request): Response
    {
        $view = in_array($request->query('vue'), ['jour', 'semaine', 'mois'], true) ? (string) $request->query('vue') : 'semaine';
        $date = $this->parseDate((string) $request->query('date', '')) ?? $this->clock->now()->setTime(0, 0);

        [$start, $end, $prev, $next] = match ($view) {
            'jour' => [$date, $date->modify('+1 day'), $date->modify('-1 day'), $date->modify('+1 day')],
            'mois' => (function () use ($date): array {
                $first = $date->modify('first day of this month');
                $gridStart = $first->modify('-' . ((int) $first->format('N') - 1) . ' days');
                $last = $date->modify('last day of this month');
                $gridEnd = $last->modify('+' . (8 - (int) $last->format('N')) . ' days');

                return [$gridStart, $gridEnd, $first->modify('-1 month'), $first->modify('+1 month')];
            })(),
            default => (function () use ($date): array {
                $monday = $date->modify('-' . ((int) $date->format('N') - 1) . ' days');

                return [$monday, $monday->modify('+7 days'), $monday->modify('-7 days'), $monday->modify('+7 days')];
            })(),
        };

        $byDay = [];
        foreach ($this->bookings->between($start->format(DATE_ATOM), $end->format(DATE_ATOM), $request->query('annulees') === '1') as $b) {
            $local = (new DateTimeImmutable((string) $b['starts_at']))->setTimezone($this->clock->zone());
            $byDay[$local->format('Y-m-d')][] = $b;
        }

        $days = [];
        for ($d = $start; $d < $end; $d = $d->modify('+1 day')) {
            $days[] = ['date' => $d->format('Y-m-d'), 'in_month' => $view !== 'mois' || $d->format('m') === $date->format('m'), 'bookings' => $byDay[$d->format('Y-m-d')] ?? []];
        }

        return $this->render('admin/bookings/calendar.twig', [
            'view' => $view,
            'date' => $date->format('Y-m-d'),
            'days' => $days,
            'weeks' => array_chunk($days, 7),
            'prev' => $prev->format('Y-m-d'),
            'next' => $next->format('Y-m-d'),
            'today' => $this->clock->now()->format('Y-m-d'),
            'show_cancelled' => $request->query('annulees') === '1',
        ]);
    }

    // ---------- Fiche ----------

    public function show(int $id): Response
    {
        $booking = $this->bookings->find($id) ?? throw HttpException::notFound('Rendez-vous introuvable.');

        return $this->render('admin/bookings/show.twig', [
            'booking' => $booking,
            'options' => $this->bookings->options($id),
            'history' => $this->bookings->history($id),
            'next_statuses' => array_combine(
                BookingStatus::nextStatuses((string) $booking['status']),
                array_map(static fn (string $s): string => BookingStatus::ACTIONS[$s] ?? BookingStatus::label($s), BookingStatus::nextStatuses((string) $booking['status'])),
            ),
            'is_active' => BookingStatus::isActive((string) $booking['status']),
            'can_manage' => $this->auth->can('bookings.manage'),
            'local_start' => (new DateTimeImmutable((string) $booking['starts_at']))->setTimezone($this->clock->zone()),
            'notifications' => $this->notificationLog->forBooking($id),
            'notification_labels' => NotificationAdminController::EVENT_LABELS,
            'whatsapp_url' => self::whatsappUrl($booking),
        ]);
    }

    /**
     * Lien « écrire sur WhatsApp » vers la cliente, avec un message pré-rempli.
     * Numéro français 06… / 07… converti au format international 336… / 337….
     *
     * @param array<string, mixed> $booking
     */
    public static function whatsappUrl(array $booking): ?string
    {
        $digits = preg_replace('/\D/', '', (string) ($booking['phone'] ?? '')) ?? '';
        if (str_starts_with($digits, '0033')) {
            $digits = substr($digits, 2);
        } elseif (preg_match('/^0[1-9]\d{8}$/', $digits)) {
            $digits = '33' . substr($digits, 1);
        }
        if (!preg_match('/^[1-9]\d{7,14}$/', $digits)) {
            return null;
        }

        $date = (new DateTimeImmutable((string) $booking['starts_at']))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $message = sprintf('Bonjour %s, à propos de votre rendez-vous du %s à %s (%s) : ', $booking['first_name'], $date->format('d/m'), $date->format('H\hi'), $booking['reference']);

        return 'https://wa.me/' . $digits . '?text=' . rawurlencode($message);
    }

    public function updateStatus(Request $request, int $id): Response
    {
        $to = (string) $request->input('status', '');
        $note = mb_substr(trim((string) $request->input('note', '')), 0, 300);

        try {
            $this->service->changeStatus($id, $to, $this->userId($request), $note !== '' ? $note : null);
        } catch (BookingException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirectTo('admin.bookings.show', ['id' => $id]);
        }

        $this->audit->log('booking.status_changed', $this->userId($request), $request, 'booking', $id, ['status' => $to]);
        $this->flash('success', 'Statut mis à jour : ' . BookingStatus::label($to) . '.');

        return $this->redirectTo('admin.bookings.show', ['id' => $id]);
    }

    public function reschedule(Request $request, int $id): Response
    {
        $data = $this->validate($request->all(), ['date' => 'required|date', 'time' => 'required|regex:/^([01]\d|2[0-3]):[0-5]\d$/'], ['date' => 'date', 'time' => 'heure']);
        $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $data['date'] . ' ' . $data['time'], $this->clock->zone());
        if ($start === false) {
            throw ValidationException::withMessages(['date' => 'Date invalide.']);
        }

        try {
            $this->service->reschedule($id, $start, $this->userId($request), $request->input('force') === '1');
        } catch (BookingException $e) {
            throw ValidationException::withMessages(['time' => $e->getMessage() . ' Cochez « Ignorer les horaires » pour forcer (le chevauchement avec un autre rendez-vous reste impossible).']);
        }

        $this->audit->log('booking.rescheduled', $this->userId($request), $request, 'booking', $id, ['starts_at' => $start->format(DATE_ATOM)]);
        $this->flash('success', 'Rendez-vous déplacé au ' . $start->format('d/m/Y à H:i') . '.');

        return $this->redirectTo('admin.bookings.show', ['id' => $id]);
    }

    public function updatePrice(Request $request, int $id): Response
    {
        $raw = trim((string) $request->input('final_total', ''));
        $amount = $raw === '' ? null : Slug::parsePrice($raw);
        if ($raw !== '' && $amount === null) {
            throw ValidationException::withMessages(['final_total' => 'Prix invalide (ex. 120 ou 120,50).']);
        }

        $this->service->setFinalTotal($id, $amount, $this->userId($request));
        $this->flash('success', $amount !== null ? 'Prix final enregistré.' : 'Prix final retiré.');

        return $this->redirectTo('admin.bookings.show', ['id' => $id]);
    }

    // ---------- Saisie manuelle ----------

    public function create(Request $request): Response
    {
        $catalog = $this->catalog->selectorData();
        $serviceId = filter_var($request->query('service'), FILTER_VALIDATE_INT) ?: null;
        $service = null;
        foreach ($catalog as $category) {
            foreach ($category['services'] as $s) {
                if ($s['id'] === $serviceId) {
                    $service = $s;
                }
            }
        }

        // Depuis une fiche cliente : coordonnées pré-remplies (la réservation retrouvera la même fiche).
        $customerId = filter_var($request->query('cliente'), FILTER_VALIDATE_INT) ?: null;
        $customer = $customerId !== null && $this->auth->can('customers.view')
            ? $this->customers->find($customerId)
            : null;

        return $this->render('admin/bookings/create.twig', [
            'catalog' => $catalog,
            'service' => $service,
            'prefill' => $customer ?? [],
            'date' => (string) $request->query('date', $this->clock->now()->format('Y-m-d')),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request->all(), [
            'service' => 'required|integer',
            'date' => 'required|date',
            'time' => 'required|regex:/^([01]\d|2[0-3]):[0-5]\d$/',
            'first_name' => 'required|max:80',
            'last_name' => 'required|max:80',
            'email' => 'nullable|email',
            'phone' => 'nullable|regex:/^\+?[0-9 .()-]{6,30}$/',
            'comment' => 'nullable|max:1000',
            'source' => 'required|in:admin,phone,whatsapp',
            'status' => 'required|in:pending,confirmed',
        ], [
            'service' => 'prestation', 'date' => 'date', 'time' => 'heure', 'first_name' => 'prénom', 'last_name' => 'nom',
            'email' => 'e-mail', 'phone' => 'téléphone', 'comment' => 'note', 'source' => 'origine', 'status' => 'statut',
        ]);

        if (($data['email'] ?? '') === '' && ($data['phone'] ?? '') === '') {
            throw ValidationException::withMessages(['phone' => 'Indiquez au moins un téléphone ou un e-mail.']);
        }

        $combo = (string) $request->input('combo', '');
        [$variant, $length] = preg_match('/^(\d+)-(\d+)$/', $combo, $m) ? [(int) $m[1], (int) $m[2]] : [null, null];
        $options = array_values(array_filter(array_map('intval', array_filter((array) $request->input('options', []), 'is_scalar'))));
        $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $data['date'] . ' ' . $data['time'], $this->clock->zone());

        try {
            $result = $this->service->create(new BookingRequest(
                serviceId: (int) $data['service'],
                variantId: $variant,
                lengthId: $length,
                optionIds: $options,
                startsAt: $start ?: throw ValidationException::withMessages(['date' => 'Date invalide.']),
                firstName: (string) $data['first_name'],
                lastName: (string) $data['last_name'],
                email: ($data['email'] ?? '') !== '' ? (string) $data['email'] : null,
                phone: ($data['phone'] ?? '') !== '' ? (string) $data['phone'] : null,
                comment: ($data['comment'] ?? '') !== '' ? (string) $data['comment'] : null,
                rulesAccepted: false,
                source: (string) $data['source'],
                createdBy: $this->userId($request),
                forcedStatus: (string) $data['status'],
                ignoreSchedule: $request->input('force') === '1',
            ));
        } catch (BookingException $e) {
            throw ValidationException::withMessages([$e->field === 'time' ? 'time' : 'service' => $e->getMessage()]);
        }

        $id = (int) $result->booking['id'];
        $this->audit->log('booking.created_manually', $this->userId($request), $request, 'booking', $id);
        $this->flash('success', "Rendez-vous {$result->booking['reference']} enregistré.");

        return $this->redirectTo('admin.bookings.show', ['id' => $id]);
    }

    // ---------- Outils ----------

    /** @return array{from: ?string, to: ?string, status: ?string, service: ?int, q: ?string, order: string} */
    private function filters(Request $request): array
    {
        $period = (string) $request->query('periode', 'avenir');
        $today = $this->clock->now()->format('Y-m-d');
        $from = $this->parseDate((string) $request->query('du', ''))?->format('Y-m-d');
        $to = $this->parseDate((string) $request->query('au', ''))?->format('Y-m-d');
        if ($from === null && $to === null) {
            [$from, $to] = match ($period) {
                'aujourdhui' => [$today, $today],
                'passes' => [null, $this->clock->now()->modify('-1 day')->format('Y-m-d')],
                'tous' => [null, null],
                default => [$today, null],
            };
        }

        $status = (string) $request->query('statut', '');

        return [
            'periode' => $period,
            'from' => $from,
            'to' => $to,
            'status' => isset(BookingStatus::LABELS[$status]) ? $status : null,
            'service' => filter_var($request->query('prestation'), FILTER_VALIDATE_INT) ?: null,
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 80) ?: null,
            'order' => $period === 'passes' ? 'desc' : 'asc',
        ];
    }

    /** @return array<int, string> */
    private function serviceOptions(): array
    {
        $options = [];
        foreach ($this->catalog->categoriesWithServices() as $category) {
            foreach ($category['services'] as $s) {
                $options[$s['id']] = $s['name'];
            }
        }

        return $options;
    }

    private function parseDate(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->clock->zone());

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    private function tz(): string
    {
        return $this->clock->zone()->getName();
    }

    /** Neutralise les formules dans Excel (injection CSV). */
    private static function csvCell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'" . $text : $text;
    }

    private function userId(Request $request): ?int
    {
        $user = $request->attribute('user');

        return $user instanceof User ? $user->id : null;
    }
}
