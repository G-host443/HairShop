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
use App\Services\Auth\PasswordPolicy;
use App\Services\Auth\PasswordResetService;

final class PasswordResetController extends Controller
{
    public function __construct(
        View $view,
        Router $router,
        Session $session,
        private readonly PasswordResetService $resets,
        private readonly PasswordPolicy $policy,
    ) {
        parent::__construct($view, $router, $session);
    }

    public function showRequestForm(): Response
    {
        return $this->render('admin/auth/forgot-password.twig');
    }

    public function sendLink(Request $request): Response
    {
        $data = $this->validate($request->all(), ['email' => 'required|email'], ['email' => 'adresse e-mail']);

        $this->resets->sendResetLink((string) $data['email'], $request);

        // Même message que le compte existe ou non.
        $this->flash('success', 'Si cette adresse correspond à un compte, un lien de réinitialisation vient d\'être envoyé. Il est valable 60 minutes.');

        return $this->redirectTo('admin.login');
    }

    public function showResetForm(string $token): Response
    {
        if (!$this->resets->isValidToken($token)) {
            return $this->render('admin/auth/reset-password-invalid.twig', [], 410);
        }

        return $this->render('admin/auth/reset-password.twig', [
            'token' => $token,
            'min_length' => $this->policy->minLength(),
        ]);
    }

    public function reset(Request $request, string $token): Response
    {
        $data = $this->validate($request->all(), [
            'password' => 'required|confirmed',
        ], ['password' => 'nouveau mot de passe']);

        $problems = $this->policy->check((string) $data['password']);
        if ($problems !== []) {
            throw ValidationException::withMessages(['password' => $problems]);
        }

        if (!$this->resets->reset($token, (string) $data['password'], $request)) {
            return $this->render('admin/auth/reset-password-invalid.twig', [], 410);
        }

        $this->flash('success', 'Votre mot de passe a été modifié. Vous pouvez vous connecter.');

        return $this->redirectTo('admin.login');
    }
}
