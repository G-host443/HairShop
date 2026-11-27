<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\Validation\Validator;
use App\Repositories\BookingRepository;
use App\Services\Account\AccountService;
use App\Services\Auth\AuthService;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingRequest;
use App\Services\Booking\BookingService;
use App\Services\Booking\SlotUnavailableException;
use App\Services\Catalog\BookingLinkBuilder;
use App\Services\Catalog\CatalogReader;
use App\Services\Catalog\PricingException;
use App\Services\Catalog\PricingService;
use App\Services\Catalog\Quote;
use App\Services\Content\PageRenderer;
use App\Services\Settings\SettingsService;
use DateTimeImmutable;

/**
 * Parcours de réservation /reserver, en étapes servies par le serveur (fonctionne sans JavaScript) :
 *   1. prestation → 2. taille, longueur, options → 3. date → 4. créneau → 5. coordonnées → 6. confirmation.
 * L'état du parcours est porté par l'adresse (paramètres GET) ; tout est revérifié à chaque étape.
 */
final class BookingController
{
    public const STEPS = [
        'service' => 'Prestation',
        'config' => 'Options',
        'date' => 'Date',
        'time' => 'Créneau',
        'details' => 'Coordonnées',
        'done' => 'Confirmation',
    ];

    private const CONFIRMATION_KEY = 'booking.confirmation';

    public function __construct(
        private readonly PageRenderer $renderer,
        private readonly CatalogReader $catalog,
        private readonly PricingService $pricing,
        private readonly AvailabilityService $availability,
        private readonly BookingService $bookings,
        private readonly BookingRepository $repository,
        private readonly BookingLinkBuilder $links,
        private readonly SettingsService $settings,
        private readonly Session $session,
        private readonly Clock $clock,
        private readonly Database $db,
        private readonly AuthService $auth,
        private readonly AccountService $accounts,
    ) {
    }

    public function show(Request $request): Response
    {
        if (!$this->settings->bool('booking.online_enabled', true)) {
            return $this->view('booking-closed', ['contact' => $this->links->config()], 'Réservation');
        }

        $state = self::state($request->allQuery());
        $catalog = $this->catalog->selectorData();
        $service = $state['service'] !== null ? $this->findService($catalog, $state['service']) : null;

        // Étape 1 : choix de la prestation.
        if ($service === null) {
            return $this->view('booking', ['step' => 'service', 'categories' => $catalog, 'error' => $state['service'] !== null ? 'Cette prestation n\'est pas disponible.' : null]);
        }

        // Étape 2 : taille, longueur et options (sautée pour une prestation simple sans option).
        $needsConfig = $service['simple'] === null || $service['option_groups'] !== [];
        if ($needsConfig && !$state['configured']) {
            return $this->view('booking', ['step' => 'config', 'service' => $service, 'state' => $state, 'error' => null]);
        }

        try {
            $quote = $this->pricing->quote($service['id'], $state['variant'], $state['length'], $state['options']);
        } catch (PricingException $e) {
            return $this->view('booking', ['step' => 'config', 'service' => $service, 'state' => $state, 'error' => $e->getMessage()]);
        }

        $base = ['service' => $service, 'state' => $state, 'quote' => $quote, 'summary' => $this->summary($quote, $state)];

        // Étape 3 : date (calendrier du mois).
        if ($state['date'] === null || $this->availability->slotsForDate($state['date'], $quote->durationMinutes) === []) {
            return $this->view('booking', $base + [
                'step' => 'date',
                'calendar' => $this->calendar($state, $quote->durationMinutes),
                'error' => $this->session->get('booking.error')
                    ?? ($state['date'] !== null ? 'Plus aucun créneau ce jour-là : choisissez une autre date.' : null),
            ]);
        }

        // Étape 4 : créneau.
        $slots = $this->availability->slotsForDate($state['date'], $quote->durationMinutes);
        $times = array_map(static fn (DateTimeImmutable $s): string => $s->format('H:i'), $slots);
        if ($state['time'] === null || !in_array($state['time'], $times, true)) {
            return $this->view('booking', $base + [
                'step' => 'time',
                'slots' => $slots,
                'error' => $state['time'] !== null ? 'Ce créneau n\'est plus disponible : choisissez-en un autre.' : $this->session->get('booking.error'),
            ]);
        }

        // Étape 5 : coordonnées et règles (pré-remplies pour une cliente connectée).
        $user = $this->auth->user();
        $account = $user?->isCustomer() ? ['first_name' => $user->firstName, 'last_name' => $user->lastName, 'email' => $user->email, 'phone' => (string) $user->phone] : null;

        return $this->view('booking', $base + [
            'step' => 'details',
            'account' => $account,
            'login_url' => '/compte/connexion?retour=' . rawurlencode('/reserver?' . self::query($state)),
            'rules' => array_column($this->db->fetchAll('SELECT body FROM booking_rules WHERE is_active ORDER BY position, id'), 'body'),
            'require_rules' => $this->settings->bool('booking.require_rules_acceptance', true),
            'cancellation_hours' => $this->settings->int('booking.cancellation_hours', 48),
            'error' => null,
        ]);
    }

    public function store(Request $request): Response
    {
        $state = self::state($request->all());
        $back = '/reserver?' . self::query($state);

        // Champ piège anti-robots.
        if (trim((string) $request->input('website', '')) !== '') {
            return Response::redirect('/reserver', 303);
        }

        $data = Validator::make($request->all(), [
            'first_name' => 'required|max:80',
            'last_name' => 'required|max:80',
            'email' => 'required|email',
            'phone' => 'required|regex:/^\+?[0-9 .()-]{6,30}$/',
            'comment' => 'nullable|max:1000',
        ], [
            'first_name' => 'prénom', 'last_name' => 'nom', 'email' => 'adresse e-mail', 'phone' => 'téléphone', 'comment' => 'commentaire',
        ])->validate();

        if ($state['service'] === null || $state['date'] === null || $state['time'] === null) {
            throw ValidationException::withMessages(['booking' => 'Votre sélection est incomplète. Reprenez la réservation.'], '/reserver');
        }

        $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $state['date'] . ' ' . $state['time'], $this->clock->zone());
        if ($start === false) {
            throw ValidationException::withMessages(['booking' => 'Date ou heure invalide.'], '/reserver');
        }

        // Cliente connectée : la réservation est enregistrée sur la fiche de son compte.
        $user = $this->auth->user();
        $customerId = $user?->isCustomer() ? (int) $this->accounts->customerFor($user)['id'] : null;

        try {
            $result = $this->bookings->create(new BookingRequest(
                serviceId: $state['service'],
                variantId: $state['variant'],
                lengthId: $state['length'],
                optionIds: $state['options'],
                startsAt: $start,
                firstName: (string) $data['first_name'],
                lastName: (string) $data['last_name'],
                email: (string) $data['email'],
                phone: (string) $data['phone'],
                comment: ($data['comment'] ?? '') !== '' ? (string) $data['comment'] : null,
                rulesAccepted: $request->input('rules') === '1',
                marketingConsent: $request->input('marketing') === '1',
                customerId: $customerId,
            ));
        } catch (SlotUnavailableException $e) {
            // Retour au choix du créneau, avec la sélection conservée.
            $this->session->flash('booking.error', $e->getMessage());
            $state['time'] = null;

            return Response::redirect('/reserver?' . self::query($state), 303);
        } catch (BookingException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()], $back);
        }

        // Le lien de gestion n'est montré qu'à la page de confirmation (et envoyé par e-mail à l'étape 8).
        $this->session->put(self::CONFIRMATION_KEY, ['reference' => $result->booking['reference'], 'token' => $result->manageToken]);

        return Response::redirect('/reserver/confirmation', 303);
    }

    public function confirmation(): Response
    {
        $data = $this->session->get(self::CONFIRMATION_KEY);
        $booking = is_array($data) ? $this->repository->findByReference((string) $data['reference']) : null;
        if ($booking === null) {
            return Response::redirect('/reserver', 302);
        }

        return $this->view('booking-done', [
            'booking' => $booking,
            'options' => $this->repository->options((int) $booking['id']),
            'manage_url' => '/rdv/' . $data['token'],
            'is_home_location' => $this->settings->string('contact.location_type', 'home') === 'home',
            'steps' => self::STEPS,
        ], 'Réservation enregistrée');
    }

    // ---------- Outils ----------

    /**
     * État du parcours lu depuis les paramètres, validé et typé.
     *
     * @param array<string, mixed> $input
     * @return array{service: ?int, variant: ?int, length: ?int, options: list<int>, configured: bool, date: ?string, time: ?string, month: ?string}
     */
    public static function state(array $input): array
    {
        $int = static fn ($v): ?int => ($i = filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) === false ? null : $i;

        $variant = $int($input['variant'] ?? null);
        $length = $int($input['length'] ?? null);
        // Le formulaire d'options envoie la combinaison « variante-longueur » en un seul champ.
        if (isset($input['combo']) && is_string($input['combo']) && preg_match('/^(\d+)-(\d+)$/', $input['combo'], $m)) {
            [$variant, $length] = [(int) $m[1], (int) $m[2]];
        }

        $date = isset($input['date']) && is_string($input['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['date']) ? $input['date'] : null;
        $time = isset($input['time']) && is_string($input['time']) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $input['time']) ? $input['time'] : null;
        $month = isset($input['mois']) && is_string($input['mois']) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $input['mois']) ? $input['mois'] : null;

        $options = [];
        foreach ((array) ($input['options'] ?? []) as $value) {
            if (is_array($value)) {
                continue;
            }
            $id = $int($value);
            if ($id !== null) {
                $options[] = $id;
            }
        }

        return [
            'service' => $int($input['service'] ?? null),
            'variant' => $variant,
            'length' => $length,
            'options' => array_values(array_unique($options)),
            'configured' => ($input['c'] ?? null) === '1',
            'date' => $date,
            'time' => $date !== null ? $time : null,
            'month' => $month,
        ];
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $override */
    public static function query(array $state, array $override = []): string
    {
        $state = array_merge($state, $override);
        $params = array_filter([
            'service' => $state['service'] ?? null,
            'variant' => $state['variant'] ?? null,
            'length' => $state['length'] ?? null,
            'options' => ($state['options'] ?? []) ?: null,
            'c' => !empty($state['configured']) ? '1' : null,
            'date' => $state['date'] ?? null,
            'time' => $state['time'] ?? null,
            'mois' => $state['month'] ?? null,
        ], static fn ($v): bool => $v !== null);

        return http_build_query($params);
    }

    /** @param list<array<string, mixed>> $catalog */
    private function findService(array $catalog, int $id): ?array
    {
        foreach ($catalog as $category) {
            foreach ($category['services'] as $service) {
                if ($service['id'] === $id) {
                    return $service + ['category' => $category['name']];
                }
            }
        }

        return null;
    }

    /** @return array{label: string, total: ?string, is_quote: bool, is_from: bool, duration: string, when: ?string, promotion: ?array, discount: float} */
    private function summary(Quote $quote, array $state): array
    {
        $when = null;
        if ($state['date'] !== null) {
            $day = DateTimeImmutable::createFromFormat('!Y-m-d', $state['date'], $this->clock->zone());
            $when = $day !== false ? $day->format('Y-m-d') . ($state['time'] !== null ? ' ' . $state['time'] : '') : null;
        }

        return [
            'label' => $quote->fullLabel(),
            'total' => $quote->total(),
            'is_quote' => $quote->isQuote(),
            'is_from' => $quote->isFromPrice(),
            'duration' => CatalogReader::duration($quote->durationMinutes),
            'when' => $when,
            'promotion' => $quote->discount() > 0 ? $quote->promotion : null,
            'discount' => $quote->discount(),
        ];
    }

    /**
     * Calendrier du mois : semaines de lundi à dimanche, jours disponibles cliquables.
     *
     * @return array<string, mixed>
     */
    private function calendar(array $state, int $duration): array
    {
        [$first, $last] = $this->availability->bookingWindow();
        $month = $state['month'] ?? substr($state['date'] ?? $first, 0, 7);
        $month = max(substr($first, 0, 7), min(substr($last, 0, 7), $month));

        $start = new DateTimeImmutable($month . '-01', $this->clock->zone());
        $end = $start->modify('last day of this month');
        $available = array_flip($this->availability->availableDates(max($first, $start->format('Y-m-d')), min($last, $end->format('Y-m-d')), $duration));

        // Du lundi précédant le 1er au dimanche suivant le dernier jour du mois.
        $days = [];
        $cursor = $start->modify('-' . ((int) $start->format('N') - 1) . ' days');
        $stop = $end->modify('+' . (7 - (int) $end->format('N')) . ' days');
        for (; $cursor <= $stop; $cursor = $cursor->modify('+1 day')) {
            $key = $cursor->format('Y-m-d');
            $days[] = [
                'date' => $key,
                'day' => (int) $cursor->format('j'),
                'in_month' => $cursor->format('Y-m') === $month,
                'available' => isset($available[$key]),
            ];
        }
        $weeks = array_chunk($days, 7);

        $prev = $start->modify('-1 month')->format('Y-m');
        $next = $start->modify('+1 month')->format('Y-m');

        return [
            'month' => $month,
            'weeks' => $weeks,
            'prev' => $prev >= substr($first, 0, 7) ? $prev : null,
            'next' => $next <= substr($last, 0, 7) ? $next : null,
            'has_any' => $available !== [],
        ];
    }

    /** @param array<string, mixed> $data */
    private function view(string $template, array $data, string $title = 'Réserver'): Response
    {
        return Response::html($this->renderer->renderView($template, $data + ['steps' => self::STEPS], [
            'title' => $title,
            'seo_description' => 'Réservez votre coiffure en ligne : choisissez la prestation, la date et le créneau.',
        ], '/reserver'))->withHeader('X-Robots-Tag', 'noindex');
    }
}
