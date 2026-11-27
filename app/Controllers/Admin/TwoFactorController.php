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
use App\Services\Auth\Totp;
use App\Services\Auth\TwoFactorService;
use App\Services\Settings\SettingsService;

/**
 * Double authentification du compte connecté : activation (QR code + premier code), codes de secours, désactivation.
 * Désactivée par défaut : la page indique comment l'autoriser dans Paramètres → Sécurité.
 */
final class TwoFactorController extends Controller
{
    private const CODES_FLASH = 'two_factor.recovery_codes';

    public function __construct(
        View $view,
        Router $router,
        Session $session,
        private readonly TwoFactorService $twoFactor,
        private readonly AuthService $auth,
        private readonly SettingsService $settings,
    ) {
        parent::__construct($view, $router, $session);
    }

    public function show(Request $request): Response
    {
        $user = self::user($request);
        $configured = $this->twoFactor->isConfigured($user->id);
        $setup = null;
        if (!$configured && $this->twoFactor->isEnabled() && $request->query('configurer') !== null) {
            $secret = $this->twoFactor->setupSecret();
            $setup = [
                'secret' => Totp::formatSecret($secret),
                'uri' => Totp::uri($secret, $user->email, $this->settings->string('general.site_name', 'Hair_style by Garance')),
            ];
        }

        return $this->render('admin/profile/two-factor.twig', [
            'enabled' => $this->twoFactor->isEnabled(),
            'configured' => $configured,
            'setup' => $setup,
            'remaining' => $configured ? $this->twoFactor->remainingRecoveryCodes($user->id) : 0,
            'new_codes' => $this->session->pull(self::CODES_FLASH),
            'can_settings' => $this->auth->can('settings.manage'),
        ]);
    }

    public function confirm(Request $request): Response
    {
        $user = self::user($request);
        if (!$this->twoFactor->isEnabled()) {
            return $this->redirectTo('admin.profile.two_factor');
        }
        $codes = $this->twoFactor->confirm($user->id, (string) $request->input('code', ''), $request);
        if ($codes === null) {
            throw ValidationException::withMessages(['code' => 'Code incorrect. Vérifiez que l\'heure de votre téléphone est à jour, puis saisissez le code affiché.'],
                $this->router->url('admin.profile.two_factor') . '?configurer=1');
        }
        $this->session->put(self::CODES_FLASH, $codes);
        $this->flash('success', 'Double authentification activée : un code vous sera demandé à chaque connexion.');

        return $this->redirectTo('admin.profile.two_factor');
    }

    public function regenerateCodes(Request $request): Response
    {
        $user = $this->confirmPassword($request);
        $this->session->put(self::CODES_FLASH, $this->twoFactor->regenerateRecoveryCodes($user->id, $request));
        $this->flash('success', 'Nouveaux codes de secours créés : les anciens ne fonctionnent plus.');

        return $this->redirectTo('admin.profile.two_factor');
    }

    public function disable(Request $request): Response
    {
        $user = $this->confirmPassword($request);
        $this->twoFactor->disable($user->id, $request);
        $this->flash('success', 'Double authentification désactivée pour votre compte.');

        return $this->redirectTo('admin.profile.two_factor');
    }

    private function confirmPassword(Request $request): User
    {
        $user = self::user($request);
        if (!$this->auth->verifyPassword($user, (string) $request->input('password', ''))) {
            throw ValidationException::withMessages(['password' => 'Mot de passe incorrect.']);
        }

        return $user;
    }

    private static function user(Request $request): User
    {
        $user = $request->attribute('user');
        if (!$user instanceof User) {
            throw \App\Core\Exceptions\HttpException::forbidden();
        }

        return $user;
    }
}
