<?php

declare(strict_types=1);

namespace App\Controllers\Account;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\Validation\Validator;
use App\Models\User;
use App\Repositories\BookingRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\UserRepository;
use App\Services\Account\AccountException;
use App\Services\Account\AccountService;
use App\Services\Auth\AuthService;
use App\Services\Auth\PasswordPolicy;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Services\Booking\BookingStatus;
use App\Services\Catalog\CatalogReader;
use App\Services\Content\PageRenderer;
use App\Repositories\ReviewRepository;
use App\Services\Review\ReviewService;
use DateTimeImmutable;

/**
 * Espace cliente : rendez-vous, favoris, profil, mot de passe, export des données, suppression du compte.
 */
final class AccountController
{
    public function __construct(
        private readonly PageRenderer $renderer,
        private readonly AccountService $accounts,
        private readonly CustomerRepository $customers,
        private readonly BookingRepository $bookingRepository,
        private readonly BookingService $bookings,
        private readonly CatalogReader $catalog,
        private readonly AuthService $auth,
        private readonly UserRepository $users,
        private readonly PasswordPolicy $policy,
        private readonly Session $session,
        private readonly Clock $clock,
        private readonly Database $db,
        private readonly ReviewService $reviewService,
        private readonly ReviewRepository $reviews,
    ) {
    }

    public function dashboard(Request $request): Response
    {
        $user = self::user($request);
        $customer = $this->accounts->customerFor($user);
        $now = $this->clock->now();

        $rows = $this->db->fetchAll(
            "SELECT b.*, st.label AS status_label FROM bookings b JOIN booking_statuses st ON st.code = b.status
             WHERE b.customer_id = :c ORDER BY b.starts_at DESC",
            ['c' => $customer['id']],
        );
        $upcoming = [];
        $past = [];
        foreach ($rows as $row) {
            $isUpcoming = BookingStatus::isActive((string) $row['status']) && new DateTimeImmutable((string) $row['ends_at']) > $now;
            $row['can_cancel'] = $isUpcoming && $this->bookings->canCustomerCancel($row);
            $isUpcoming ? array_unshift($upcoming, $row) : $past[] = $row;
        }

        return $this->view('account/dashboard', 'Mon espace', [
            'user' => $user,
            'upcoming' => $upcoming,
            'past' => $past,
            'tab' => 'bookings',
        ]);
    }

    public function showBooking(Request $request, string $reference): Response
    {
        $booking = $this->ownedBooking($request, $reference);

        return $this->view('account/booking', 'Rendez-vous ' . $booking['reference'], [
            'booking' => $booking,
            'options' => $this->bookingRepository->options((int) $booking['id']),
            'can_cancel' => $this->bookings->canCustomerCancel($booking),
            'can_review' => $this->reviewService->canReview($booking),
            'review' => $this->reviews->findByBooking((int) $booking['id']),
            'default_author' => ReviewService::defaultAuthorName($booking),
            'tab' => 'bookings',
        ]);
    }

    public function review(Request $request, string $reference): Response
    {
        $booking = $this->ownedBooking($request, $reference);
        try {
            $this->reviewService->submitForBooking($booking, $request->all());
        } catch (ValidationException $e) {
            throw ValidationException::withMessages($e->errors(), '/compte/rendez-vous/' . $booking['reference'] . '#avis');
        }
        $this->session->flash('success', 'Merci pour votre avis ! Il sera publié après relecture.');

        return Response::redirect('/compte/rendez-vous/' . $booking['reference'] . '#avis', 303);
    }

    public function cancelBooking(Request $request, string $reference): Response
    {
        $booking = $this->ownedBooking($request, $reference);

        try {
            $this->bookings->cancelByCustomer($booking, mb_substr(trim((string) $request->input('reason', '')), 0, 300));
            $this->session->flash('success', 'Votre rendez-vous est bien annulé.');
        } catch (BookingException $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return Response::redirect('/compte/rendez-vous/' . $booking['reference'], 303);
    }

    public function favorites(Request $request): Response
    {
        $customer = $this->accounts->customerFor(self::user($request));
        $ids = $this->customers->favoriteServiceIds((int) $customer['id']);

        $services = [];
        foreach ($this->catalog->categoriesWithServices() as $category) {
            foreach ($category['services'] as $service) {
                if (in_array($service['id'], $ids, true)) {
                    $services[] = $service + ['category' => $category['name']];
                }
            }
        }

        return $this->view('account/favorites', 'Mes favoris', ['services' => $services, 'tab' => 'favorites']);
    }

    public function toggleFavorite(Request $request, int $serviceId): Response
    {
        $customer = $this->accounts->customerFor(self::user($request));
        $exists = (bool) $this->db->fetchValue('SELECT EXISTS (SELECT 1 FROM services WHERE id = :id AND is_active)', ['id' => $serviceId]);
        if (!$exists) {
            throw HttpException::notFound();
        }

        $added = $this->customers->toggleFavorite((int) $customer['id'], $serviceId);
        if ($request->wantsJson()) {
            return Response::json(['favorite' => $added]);
        }
        $this->session->flash('success', $added ? 'Prestation ajoutée à vos favoris.' : 'Prestation retirée de vos favoris.');

        $back = (string) $request->input('return_to', '/compte/favoris');

        return Response::redirect(preg_match('~^/(compte|prestations)(/[a-z0-9/-]*)?$~', $back) ? $back : '/compte/favoris', 303);
    }

    public function profile(Request $request): Response
    {
        $user = self::user($request);

        return $this->view('account/profile', 'Mon profil', [
            'user' => $user,
            'customer' => $this->accounts->customerFor($user),
            'min_length' => $this->policy->minLength(),
            'tab' => 'profile',
        ]);
    }

    public function updateProfile(Request $request): Response
    {
        $user = self::user($request);
        $data = Validator::make($request->all(), [
            'first_name' => 'required|max:80',
            'last_name' => 'required|max:80',
            'email' => 'required|email',
            'phone' => 'nullable|regex:/^\+?[0-9 .()-]{6,30}$/',
        ], ['first_name' => 'prénom', 'last_name' => 'nom', 'email' => 'adresse e-mail', 'phone' => 'téléphone'])->validate();

        if ($this->users->emailTakenByOther((string) $data['email'], $user->id)) {
            throw ValidationException::withMessages(['email' => 'Cette adresse est déjà utilisée par un autre compte.']);
        }

        $emailChanged = $this->accounts->updateProfile($user, [
            'first_name' => (string) $data['first_name'],
            'last_name' => (string) $data['last_name'],
            'email' => (string) $data['email'],
            'phone' => ($data['phone'] ?? '') !== '' ? (string) $data['phone'] : null,
            'marketing' => $request->input('marketing') === '1',
        ], $request);
        $this->auth->refreshUser();

        $this->session->flash('success', $emailChanged
            ? 'Profil enregistré. Confirmez votre nouvelle adresse grâce au lien qui vient de vous être envoyé.'
            : 'Profil enregistré.');

        return Response::redirect('/compte/profil', 303);
    }

    public function updatePassword(Request $request): Response
    {
        $user = self::user($request);
        $data = Validator::make($request->all(), ['current_password' => 'required', 'password' => 'required|confirmed'], ['current_password' => 'mot de passe actuel', 'password' => 'nouveau mot de passe'])->validate();

        if (!$this->auth->verifyPassword($user, (string) $data['current_password'])) {
            throw ValidationException::withMessages(['current_password' => 'Le mot de passe actuel est incorrect.']);
        }
        $problems = $this->policy->check((string) $data['password'], $user->email);
        if ($problems !== []) {
            throw ValidationException::withMessages(['password' => $problems]);
        }

        $this->auth->changePassword($user, (string) $data['password'], $request);
        $this->session->flash('success', 'Mot de passe modifié. Vos autres sessions ont été déconnectées.');

        return Response::redirect('/compte/profil', 303);
    }

    public function export(Request $request): Response
    {
        $json = json_encode($this->accounts->export(self::user($request)), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return new Response($json, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="mes-donnees-' . $this->clock->now()->format('Y-m-d') . '.json"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function delete(Request $request): Response
    {
        $user = self::user($request);
        if (!$this->auth->verifyPassword($user, (string) $request->input('password', ''))) {
            throw ValidationException::withMessages(['delete_password' => 'Mot de passe incorrect.']);
        }

        try {
            $this->accounts->delete($user, $request);
        } catch (AccountException $e) {
            throw ValidationException::withMessages(['delete_password' => $e->getMessage()]);
        }

        $this->auth->logout($request, audit: false);
        $this->session->start($request->isSecure());
        $this->session->flash('success', 'Votre compte a été supprimé.');

        return Response::redirect('/', 303);
    }

    /** @return array<string, mixed> rendez-vous appartenant à la cliente connectée (sinon 404) */
    private function ownedBooking(Request $request, string $reference): array
    {
        $customer = $this->accounts->customerFor(self::user($request));
        $booking = $this->bookingRepository->findByReference($reference);

        if ($booking === null || (int) $booking['customer_id'] !== (int) $customer['id']) {
            throw HttpException::notFound('Ce rendez-vous est introuvable dans votre espace.');
        }

        return $booking;
    }

    private static function user(Request $request): User
    {
        $user = $request->attribute('user');
        if (!$user instanceof User) {
            throw HttpException::forbidden();
        }

        return $user;
    }

    /** @param array<string, mixed> $data */
    private function view(string $template, string $title, array $data): Response
    {
        return Response::html($this->renderer->renderView($template, $data, ['title' => $title], '/compte'))
            ->withHeader('X-Robots-Tag', 'noindex');
    }
}
