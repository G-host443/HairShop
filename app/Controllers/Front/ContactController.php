<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Controllers\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Router;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View;
use App\Repositories\ContactMessageRepository;
use App\Services\Notification\NotificationService;

/**
 * Formulaire de contact public. Protections : jeton CSRF, champ piège invisible pour les robots,
 * limite de 5 messages par heure et par adresse IP.
 */
final class ContactController extends Controller
{
    private const MAX_PER_HOUR = 5;

    public function __construct(
        View $view,
        Router $router,
        Session $session,
        private readonly ContactMessageRepository $messages,
        private readonly NotificationService $notifications,
    ) {
        parent::__construct($view, $router, $session);
    }

    public function store(Request $request): Response
    {
        $back = $this->backUrl($request);

        // Champ piège : invisible pour les humains, rempli par les robots. On fait comme si tout allait bien.
        if (trim((string) $request->input('website', '')) !== '') {
            $this->flash('contact_success', 'Merci, votre message a bien été envoyé.');

            return Response::redirect($back, 303);
        }

        $data = $this->validate($request->all(), [
            'name' => 'required|max:100',
            'email' => 'required|email',
            'phone' => 'nullable|max:30|regex:/^\+?[0-9 .()-]{6,30}$/',
            'message' => 'required|min:10|max:3000',
            'consent' => 'required',
        ], [
            'name' => 'nom',
            'email' => 'adresse e-mail',
            'phone' => 'téléphone',
            'message' => 'message',
            'consent' => 'consentement',
        ]);

        if ($this->messages->countRecentFromIp($request->ip(), 60) >= self::MAX_PER_HOUR) {
            throw ValidationException::withMessages(['message' => 'Vous avez envoyé plusieurs messages récemment. Réessayez dans une heure ou contactez-moi par WhatsApp.'], $back);
        }

        $messageId = $this->messages->create(
            (string) $data['name'],
            (string) $data['email'],
            ($data['phone'] ?? '') !== '' ? (string) $data['phone'] : null,
            null,
            (string) $data['message'],
            $request->ip(),
        );
        $this->notifications->contactMessage($messageId);
        $this->notifications->trySendNow();

        $this->flash('contact_success', 'Merci, votre message a bien été envoyé. Je vous réponds au plus vite.');

        return Response::redirect($back, 303);
    }

    /** Retour vers la page du formulaire (chemin du site uniquement), positionné sur la section. */
    private function backUrl(Request $request): string
    {
        $path = (string) $request->input('return_to', '/contact');

        return (preg_match('~^/[a-z0-9-]*$~', $path) === 1 ? $path : '/contact') . '#contact';
    }
}
