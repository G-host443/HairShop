<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Security\Csrf;
use App\Core\Security\PasswordHasher;
use App\Core\Session\Session;
use App\Models\User;
use App\Repositories\PasswordResetRepository;
use App\Repositories\UserRepository;
use App\Services\Audit\AuditLogger;

/**
 * Authentification par session.
 *
 * La session contient l'identifiant de l'utilisateur et une empreinte de son hachage de mot de passe :
 * changer de mot de passe déconnecte donc automatiquement toutes les autres sessions.
 */
final class AuthService
{
    private const KEY_USER = 'auth.user_id';
    private const KEY_FINGERPRINT = 'auth.fingerprint';
    private const KEY_ACTIVITY = 'auth.last_activity';

    /** Utilisateur résolu pour la requête en cours (false = résolu, aucun). */
    private User|false|null $resolved = null;

    public function __construct(
        private readonly Session $session,
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly LoginThrottler $throttler,
        private readonly PasswordResetRepository $resets,
        private readonly AuditLogger $audit,
        private readonly Csrf $csrf,
        private readonly Config $config,
        private readonly TwoFactorService $twoFactor,
    ) {
    }

    /**
     * Vérifie les identifiants pour l'accès à l'administration et ouvre la session si tout est correct.
     */
    public function attemptAdminLogin(string $email, string $password, Request $request): LoginResult
    {
        return $this->attempt($email, $password, $request, static fn (User $u): bool => $u->canAccessAdmin(), secondFactor: true);
    }

    /**
     * Deuxième étape de la connexion à l'administration : code de l'application d'authentification
     * (ou code de secours). Ouvre la session si le code est bon.
     */
    public function completeTwoFactorLogin(string $code, Request $request): ?User
    {
        $userId = $this->twoFactor->pendingUserId();
        $user = $userId !== null ? $this->users->findById($userId) : null;
        if ($user === null || !$user->canAccessAdmin()) {
            $this->twoFactor->clearPendingLogin();

            return null;
        }

        if (!$this->twoFactor->verifyPendingCode($code, $request)) {
            // Les erreurs de code comptent comme des échecs de connexion (blocage temporaire).
            $this->throttler->recordFailure($user->email, $request->ip());

            return null;
        }

        $this->login($user, $request);

        return $user;
    }

    /**
     * Connexion à l'espace cliente (comptes ayant le rôle « customer »).
     */
    public function attemptCustomerLogin(string $email, string $password, Request $request): LoginResult
    {
        return $this->attempt($email, $password, $request, static fn (User $u): bool => $u->isCustomer());
    }

    /** @param callable(User): bool $eligible */
    private function attempt(string $email, string $password, Request $request, callable $eligible, bool $secondFactor = false): LoginResult
    {
        $email = trim($email);
        $ip = $request->ip();

        $locked = $this->throttler->secondsLocked($email, $ip);
        if ($locked > 0) {
            return LoginResult::locked($locked);
        }

        $user = $email !== '' ? $this->users->findByEmail($email) : null;

        if ($user === null) {
            $this->hasher->verifyDummy($password);
            $this->throttler->recordFailure($email, $ip);

            return LoginResult::invalid();
        }

        if (!$this->hasher->verify($password, $user->passwordHash) || !$eligible($user)) {
            $this->throttler->recordFailure($email, $ip);
            $this->audit->log('auth.login_failed', $user->id, $request, 'user', $user->id);

            return LoginResult::invalid();
        }

        // Mise à niveau transparente de l'algorithme de hachage.
        if ($this->hasher->needsRehash($user->passwordHash)) {
            $this->users->updatePassword($user->id, $this->hasher->hash($password));
            $user = $this->users->findById($user->id) ?? $user;
        }

        $this->throttler->recordSuccess($email, $ip);

        if ($secondFactor && $this->twoFactor->isRequiredFor($user->id)) {
            $this->twoFactor->startPendingLogin($user->id);

            return LoginResult::twoFactor($user);
        }

        $this->login($user, $request);

        return LoginResult::success($user);
    }

    public function login(User $user, ?Request $request = null): void
    {
        // Nouvel identifiant de session : empêche la fixation de session.
        $this->session->regenerate();
        $this->session->put(self::KEY_USER, $user->id);
        $this->session->put(self::KEY_FINGERPRINT, $this->fingerprint($user));
        $this->session->put(self::KEY_ACTIVITY, time());
        $this->csrf->regenerate();

        $this->users->touchLastLogin($user->id);
        $this->audit->log('auth.login', $user->id, $request, 'user', $user->id);
        $this->resolved = $user;
    }

    /** @param bool $audit false quand le compte vient d'être supprimé (plus d'utilisateur à journaliser) */
    public function logout(?Request $request = null, bool $audit = true): void
    {
        $user = $audit ? $this->user() : null;
        if ($user !== null) {
            $this->audit->log('auth.logout', $user->id, $request, 'user', $user->id);
        }

        $this->session->invalidate();
        $this->resolved = false;
    }

    /**
     * Utilisateur connecté, ou null. Contrôle à chaque requête :
     * compte toujours actif, mot de passe inchangé, inactivité inférieure au délai.
     */
    public function user(): ?User
    {
        if ($this->resolved !== null) {
            return $this->resolved ?: null;
        }

        $this->resolved = false;
        $id = $this->session->get(self::KEY_USER);
        if (!is_int($id)) {
            return null;
        }

        $idleSeconds = (int) $this->config->get('security.session.idle_timeout', 120) * 60;
        $lastActivity = (int) $this->session->get(self::KEY_ACTIVITY, 0);
        $user = $this->users->findById($id);

        $valid = $user !== null
            && $user->isActive
            && hash_equals((string) $this->session->get(self::KEY_FINGERPRINT, ''), $this->fingerprint($user))
            && (time() - $lastActivity) <= $idleSeconds;

        if (!$valid) {
            $this->clearAuthentication();

            return null;
        }

        $this->session->put(self::KEY_ACTIVITY, time());
        $this->resolved = $user;

        return $user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function can(string $permission): bool
    {
        return $this->user()?->can($permission) ?? false;
    }

    /**
     * Change le mot de passe ; la session courante reste ouverte, les autres sont invalidées.
     */
    public function changePassword(User $user, string $newPassword, ?Request $request = null): void
    {
        $this->users->updatePassword($user->id, $this->hasher->hash($newPassword));
        $this->resets->invalidateForUser($user->id);

        $updated = $this->users->findById($user->id);
        if ($updated !== null && $this->session->get(self::KEY_USER) === $user->id) {
            $this->session->regenerate();
            $this->session->put(self::KEY_FINGERPRINT, $this->fingerprint($updated));
            $this->resolved = $updated;
        }

        $this->audit->log('auth.password_changed', $user->id, $request, 'user', $user->id);
    }

    /** Recharge l'utilisateur de la requête après une modification (profil, vérification…). */
    public function refreshUser(): void
    {
        $this->resolved = null;
    }

    public function verifyPassword(User $user, string $password): bool
    {
        return $this->hasher->verify($password, $user->passwordHash);
    }

    private function fingerprint(User $user): string
    {
        return hash_hmac('sha256', $user->id . '|' . $user->passwordHash, (string) $this->config->get('app.key'));
    }

    private function clearAuthentication(): void
    {
        $this->session->forget(self::KEY_USER);
        $this->session->forget(self::KEY_FINGERPRINT);
        $this->session->forget(self::KEY_ACTIVITY);
    }
}
