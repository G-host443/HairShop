<?php

declare(strict_types=1);

namespace App\Controllers\Account;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\Validation\Validator;
use App\Middleware\AuthenticateCustomer;
use App\Repositories\UserRepository;
use App\Services\Account\AccountService;
use App\Services\Auth\AuthService;
use App\Services\Auth\PasswordPolicy;
use App\Services\Auth\PasswordResetService;
use App\Services\Content\PageRenderer;

/**
 * Connexion, inscription, vérification d'e-mail et mot de passe oublié de l'espace cliente.
 */
final class AccountAuthController
{
    public function __construct(
        private readonly PageRenderer $renderer,
        private readonly AuthService $auth,
        private readonly AccountService $accounts,
        private readonly UserRepository $users,
        private readonly PasswordPolicy $policy,
        private readonly PasswordResetService $resets,
        private readonly Session $session,
    ) {
    }

    public function showLogin(Request $request): Response
    {
        // Retour à la page d'origine après connexion (ex. étape « coordonnées » de la réservation).
        $back = $request->query('retour');
        if (is_string($back) && preg_match('~^/(compte|reserver|prestations)(/|\?|$)~', $back)) {
            $this->session->put(AuthenticateCustomer::INTENDED_URL, $back);
        }

        return $this->view('account/login', 'Mon espace');
    }

    public function login(Request $request): Response
    {
        $data = Validator::make($request->all(), ['email' => 'required|email', 'password' => 'required|max:200'], ['email' => 'adresse e-mail', 'password' => 'mot de passe'])->validate();
        $result = $this->auth->attemptCustomerLogin((string) $data['email'], (string) $request->input('password'), $request);

        if ($result->isLocked()) {
            $minutes = (int) ceil($result->lockedSeconds / 60);
            throw ValidationException::withMessages(['email' => "Trop de tentatives. Réessayez dans {$minutes} minute" . ($minutes > 1 ? 's' : '') . '.']);
        }
        if (!$result->success || $result->user === null) {
            throw ValidationException::withMessages(['email' => 'Adresse e-mail ou mot de passe incorrect.']);
        }

        // Rattache les réservations faites sans compte (si l'adresse est vérifiée).
        $this->accounts->mergeGuestBookings($result->user);

        $intended = $this->session->pull(AuthenticateCustomer::INTENDED_URL);
        $target = is_string($intended) && preg_match('~^/(compte|reserver|prestations)(/|\?|$)~', $intended) ? $intended : '/compte';

        return Response::redirect($target, 303);
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout($request);
        $this->session->start($request->isSecure());
        $this->session->flash('success', 'Session fermée. À bientôt !');

        return Response::redirect('/compte/connexion', 303);
    }

    public function showRegister(): Response
    {
        return $this->view('account/register', 'Créer mon espace', ['min_length' => $this->policy->minLength()]);
    }

    public function register(Request $request): Response
    {
        if (trim((string) $request->input('website', '')) !== '') {
            return Response::redirect('/compte/connexion', 303); // robot
        }

        $data = Validator::make($request->all(), [
            'first_name' => 'required|max:80',
            'last_name' => 'required|max:80',
            'email' => 'required|email',
            'phone' => 'nullable|regex:/^\+?[0-9 .()-]{6,30}$/',
            'password' => 'required|confirmed',
            'consent' => 'required',
        ], ['first_name' => 'prénom', 'last_name' => 'nom', 'email' => 'adresse e-mail', 'phone' => 'téléphone', 'password' => 'mot de passe', 'consent' => 'consentement'])->validate();

        $errors = [];
        if ($this->users->emailExists((string) $data['email'])) {
            $errors['email'] = 'Un compte existe déjà avec cette adresse. Connectez-vous ou utilisez « Mot de passe oublié ».';
        }
        $problems = $this->policy->check((string) $data['password'], (string) $data['email']);
        if ($problems !== []) {
            $errors['password'] = $problems;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $user = $this->accounts->register([
            'first_name' => (string) $data['first_name'],
            'last_name' => (string) $data['last_name'],
            'email' => (string) $data['email'],
            'phone' => ($data['phone'] ?? '') !== '' ? (string) $data['phone'] : null,
            'password' => (string) $data['password'],
            'marketing' => $request->input('marketing') === '1',
        ], $request);

        $this->auth->login($user, $request);
        $this->session->flash('success', "Bienvenue {$user->firstName} ! Un e-mail vient de vous être envoyé pour confirmer votre adresse.");

        return Response::redirect('/compte', 303);
    }

    public function verify(Request $request, string $token): Response
    {
        $user = $this->accounts->verify($token);
        if ($user === null) {
            return $this->view('account/verify-invalid', 'Lien expiré', [], 410);
        }

        $this->auth->refreshUser();
        $this->session->flash('success', 'Votre adresse e-mail est confirmée. Vos rendez-vous pris avec cette adresse sont maintenant dans votre espace.');

        return Response::redirect($this->auth->user()?->id === $user->id ? '/compte' : '/compte/connexion', 303);
    }

    public function resendVerification(Request $request): Response
    {
        $user = $request->attribute('user');
        if ($user !== null && !$user->isEmailVerified()) {
            $this->accounts->resendVerification($user);
            $this->session->flash('success', "Nouveau lien envoyé à {$user->email}.");
        }

        return Response::redirect('/compte', 303);
    }

    public function showForgot(): Response
    {
        return $this->view('account/forgot', 'Mot de passe oublié');
    }

    public function sendResetLink(Request $request): Response
    {
        $data = Validator::make($request->all(), ['email' => 'required|email'], ['email' => 'adresse e-mail'])->validate();
        $this->resets->sendResetLink((string) $data['email'], $request, PasswordResetService::AREA_CUSTOMER);
        $this->session->flash('success', 'Si cette adresse correspond à un compte, un lien de réinitialisation vient d\'être envoyé. Il est valable 60 minutes.');

        return Response::redirect('/compte/connexion', 303);
    }

    public function showReset(string $token): Response
    {
        if (!$this->resets->isValidToken($token)) {
            return $this->view('account/reset-invalid', 'Lien expiré', [], 410);
        }

        return $this->view('account/reset', 'Nouveau mot de passe', ['token' => $token, 'min_length' => $this->policy->minLength()]);
    }

    public function reset(Request $request, string $token): Response
    {
        $data = Validator::make($request->all(), ['password' => 'required|confirmed'], ['password' => 'nouveau mot de passe'])->validate();
        $problems = $this->policy->check((string) $data['password']);
        if ($problems !== []) {
            throw ValidationException::withMessages(['password' => $problems]);
        }
        if (!$this->resets->reset($token, (string) $data['password'], $request)) {
            return $this->view('account/reset-invalid', 'Lien expiré', [], 410);
        }

        $this->session->flash('success', 'Votre mot de passe a été modifié. Vous pouvez vous connecter.');

        return Response::redirect('/compte/connexion', 303);
    }

    /** @param array<string, mixed> $data */
    private function view(string $template, string $title, array $data = [], int $status = 200): Response
    {
        return Response::html($this->renderer->renderView($template, $data, ['title' => $title], '/compte'), $status)
            ->withHeader('X-Robots-Tag', 'noindex');
    }
}
