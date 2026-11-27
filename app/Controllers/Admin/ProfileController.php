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
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Auth\PasswordPolicy;

/**
 * Sécurité du compte connecté : changement de mot de passe.
 */
final class ProfileController extends Controller
{
    public function __construct(
        View $view,
        Router $router,
        Session $session,
        private readonly AuthService $auth,
        private readonly PasswordPolicy $policy,
    ) {
        parent::__construct($view, $router, $session);
    }

    public function editPassword(): Response
    {
        return $this->render('admin/profile/password.twig', ['min_length' => $this->policy->minLength()]);
    }

    public function updatePassword(Request $request): Response
    {
        /** @var User $user */
        $user = $request->attribute('user');

        $data = $this->validate($request->all(), [
            'current_password' => 'required',
            'password' => 'required|confirmed',
        ], ['current_password' => 'mot de passe actuel', 'password' => 'nouveau mot de passe']);

        if (!$this->auth->verifyPassword($user, (string) $data['current_password'])) {
            throw ValidationException::withMessages(['current_password' => 'Le mot de passe actuel est incorrect.']);
        }

        $problems = $this->policy->check((string) $data['password'], $user->email);
        if ($data['password'] === $data['current_password']) {
            $problems[] = 'Le nouveau mot de passe doit être différent de l\'actuel.';
        }
        if ($problems !== []) {
            throw ValidationException::withMessages(['password' => $problems]);
        }

        $this->auth->changePassword($user, (string) $data['password'], $request);
        $this->flash('success', 'Mot de passe modifié. Vos autres sessions ont été déconnectées.');

        return $this->redirectTo('admin.profile.password');
    }
}
