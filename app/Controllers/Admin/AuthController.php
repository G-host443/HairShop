<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Router;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View;
use App\Middleware\Authenticate;
use App\Services\Auth\AuthService;
use App\Services\Auth\TwoFactorService;

final class AuthController extends Controller
{
    public function __construct(
        View $view,
        Router $router,
        Session $session,
        private readonly AuthService $auth,
        private readonly TwoFactorService $twoFactor,
    ) {
        parent::__construct($view, $router, $session);
    }

    public function showLogin(): Response
    {
        return $this->render('admin/auth/login.twig');
    }

    public function login(Request $request): Response
    {
        $data = $this->validate($request->all(), [
            'email' => 'required|email',
            'password' => 'required|max:200',
        ], ['email' => 'adresse e-mail', 'password' => 'mot de passe']);

        $result = $this->auth->attemptAdminLogin((string) $data['email'], (string) $request->input('password'), $request);

        if ($result->isLocked()) {
            $minutes = (int) ceil($result->lockedSeconds / 60);
            throw ValidationException::withMessages([
                'email' => "Trop de tentatives de connexion. Réessayez dans {$minutes} minute" . ($minutes > 1 ? 's' : '') . '.',
            ]);
        }

        if ($result->twoFactorRequired) {
            return $this->redirectTo('admin.login.two_factor');
        }

        if (!$result->success) {
            throw ValidationException::withMessages(['email' => 'Adresse e-mail ou mot de passe incorrect.']);
        }

        return $this->welcome($result->user);
    }

    /** Deuxième étape : code de l'application d'authentification. */
    public function showTwoFactor(): Response
    {
        if ($this->twoFactor->pendingUserId() === null) {
            $this->flash('info', 'Saisissez à nouveau votre adresse e-mail et votre mot de passe.');

            return $this->redirectTo('admin.login');
        }

        return $this->render('admin/auth/two-factor.twig');
    }

    public function verifyTwoFactor(Request $request): Response
    {
        if ($this->twoFactor->pendingUserId() === null) {
            $this->flash('info', 'Délai dépassé : saisissez à nouveau votre adresse e-mail et votre mot de passe.');

            return $this->redirectTo('admin.login');
        }

        $user = $this->auth->completeTwoFactorLogin((string) $request->input('code', ''), $request);
        if ($user === null) {
            if ($this->twoFactor->pendingUserId() === null) {
                $this->flash('error', 'Trop de codes incorrects : saisissez à nouveau votre adresse e-mail et votre mot de passe.');

                return $this->redirectTo('admin.login');
            }
            throw ValidationException::withMessages(['code' => 'Code incorrect ou expiré. Vérifiez l\'heure de votre téléphone, ou utilisez un code de secours.']);
        }

        return $this->welcome($user);
    }

    private function welcome(?\App\Models\User $user): Response
    {
        $intended = $this->session->pull(Authenticate::INTENDED_URL);
        $target = is_string($intended) && str_starts_with($intended, '/admin') && !str_starts_with($intended, '//')
            ? $intended
            : $this->router->url('admin.dashboard');

        $this->flash('success', 'Bonjour ' . $user?->firstName . ', bienvenue dans votre espace.');

        return Response::redirect($target, 303);
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout($request);
        // La session a été détruite : le message s'affiche dans une session neuve.
        $this->session->start($request->isSecure());
        $this->flash('success', 'Session fermée. À bientôt !');

        return $this->redirectTo('admin.login');
    }
}
