<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\Exceptions\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Repositories\BookingRepository;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Services\Catalog\BookingLinkBuilder;
use App\Services\Content\PageRenderer;
use App\Services\Settings\SettingsService;
use App\Repositories\ReviewRepository;
use App\Services\Review\ReviewService;

/**
 * « Gérer mon rendez-vous » : accès sans compte par un lien secret (/rdv/{jeton}).
 */
final class ManageBookingController
{
    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly BookingService $service,
        private readonly PageRenderer $renderer,
        private readonly SettingsService $settings,
        private readonly BookingLinkBuilder $links,
        private readonly Session $session,
        private readonly ReviewService $reviewService,
        private readonly ReviewRepository $reviews,
    ) {
    }

    public function show(string $token): Response
    {
        $booking = $this->find($token);

        return Response::html($this->renderer->renderView('booking-manage', [
            'booking' => $booking,
            'options' => $this->bookings->options((int) $booking['id']),
            'can_cancel' => $this->service->canCustomerCancel($booking),
            'cancellation_hours' => $this->settings->int('booking.cancellation_hours', 48),
            'token' => $token,
            'contact' => $this->links->config(),
            'message' => $this->session->get('manage.message'),
            'can_review' => $this->reviewService->canReview($booking),
            'review' => $this->reviews->findByBooking((int) $booking['id']),
            'default_author' => ReviewService::defaultAuthorName($booking),
        ], ['title' => 'Mon rendez-vous'], '/rdv'))->withHeader('X-Robots-Tag', 'noindex')->withHeader('Referrer-Policy', 'no-referrer');
    }

    public function cancel(Request $request, string $token): Response
    {
        $booking = $this->find($token);
        $reason = mb_substr(trim((string) $request->input('reason', '')), 0, 300);

        try {
            $this->service->cancelByCustomer($booking, $reason);
            $this->session->flash('manage.message', 'Votre rendez-vous est bien annulé. À bientôt !');
        } catch (BookingException $e) {
            $this->session->flash('manage.message', $e->getMessage());
        }

        return Response::redirect('/rdv/' . $token, 303);
    }

    public function review(Request $request, string $token): Response
    {
        try {
            $this->reviewService->submitForBooking($this->find($token), $request->all());
        } catch (ValidationException $e) {
            throw ValidationException::withMessages($e->errors(), '/rdv/' . $token . '#avis');
        }
        $this->session->flash('manage.message', 'Merci pour votre avis ! Il sera publié après relecture.');

        return Response::redirect('/rdv/' . $token . '#avis', 303);
    }

    /** @return array<string, mixed> */
    private function find(string $token): array
    {
        return $this->bookings->findByManageTokenHash(BookingService::hashToken($token))
            ?? throw HttpException::notFound('Ce lien de rendez-vous n\'est pas valide.');
    }
}
