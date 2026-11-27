<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Security\Encrypter;
use App\Core\Session\Session;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsService;

/**
 * Double authentification des comptes d'administration (code TOTP ou code de secours).
 *
 * DÉSACTIVÉE PAR DÉFAUT : tant que le réglage « security.two_factor_enabled » est à false, aucun code n'est
 * demandé, même pour un compte qui l'avait configurée. Une fois le réglage activé, chaque compte peut
 * l'activer depuis « Double authentification » ; elle est alors demandée à chaque connexion.
 */
final class TwoFactorService
{
    public const SETTING = 'security.two_factor_enabled';
    public const RECOVERY_CODES = 8;
    private const SETUP_KEY = 'auth.two_factor_setup';
    private const PENDING_KEY = 'auth.two_factor_pending';
    private const PENDING_SECONDS = 300;
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly Database $db,
        private readonly Encrypter $encrypter,
        private readonly SettingsService $settings,
        private readonly Session $session,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Réglage général (Paramètres → Sécurité). Faux par défaut. */
    public function isEnabled(): bool
    {
        return $this->settings->bool(self::SETTING, false);
    }

    /** Le compte a-t-il configuré la double authentification ? (indépendamment du réglage général) */
    public function isConfigured(int $userId): bool
    {
        return $this->db->fetchValue('SELECT two_factor_confirmed_at FROM users WHERE id = :id', ['id' => $userId]) !== null;
    }

    /** Un code sera-t-il demandé à la connexion de ce compte ? */
    public function isRequiredFor(int $userId): bool
    {
        return $this->isEnabled() && $this->isConfigured($userId);
    }

    public function remainingRecoveryCodes(int $userId): int
    {
        $raw = $this->db->fetchValue('SELECT two_factor_recovery_codes::text FROM users WHERE id = :id', ['id' => $userId]);

        return $raw !== null ? count((array) json_decode((string) $raw, true)) : 0;
    }

    // ---------- Activation ----------

    /** Clé en cours de configuration (gardée en session jusqu'à la confirmation par un premier code). */
    public function setupSecret(bool $renew = false): string
    {
        $secret = $this->session->get(self::SETUP_KEY);
        if ($renew || !is_string($secret) || $secret === '') {
            $secret = Totp::generateSecret();
            $this->session->put(self::SETUP_KEY, $secret);
        }

        return $secret;
    }

    /**
     * Confirme la configuration avec un code de l'application et enregistre la clé (chiffrée).
     *
     * @return list<string>|null codes de secours à afficher une seule fois, ou null si le code est faux
     */
    public function confirm(int $userId, string $code, ?Request $request = null): ?array
    {
        $secret = $this->session->get(self::SETUP_KEY);
        if (!is_string($secret) || $secret === '') {
            return null;
        }
        $step = Totp::verify($secret, $code, $this->clock->now()->getTimestamp());
        if ($step === null) {
            return null;
        }

        $codes = self::newRecoveryCodes();
        $this->db->execute(
            'UPDATE users SET two_factor_secret = :s, two_factor_confirmed_at = now(), two_factor_last_step = :step,
                two_factor_recovery_codes = CAST(:codes AS jsonb) WHERE id = :id',
            ['id' => $userId, 's' => $this->encrypter->encrypt($secret), 'step' => $step, 'codes' => self::hashedJson($codes)],
        );
        $this->session->forget(self::SETUP_KEY);
        $this->audit->log('auth.two_factor_enabled', $userId, $request, 'user', $userId);

        return $codes;
    }

    /** @return list<string> nouveaux codes de secours (les anciens ne fonctionnent plus) */
    public function regenerateRecoveryCodes(int $userId, ?Request $request = null): array
    {
        $codes = self::newRecoveryCodes();
        $this->db->execute('UPDATE users SET two_factor_recovery_codes = CAST(:c AS jsonb) WHERE id = :id', ['id' => $userId, 'c' => self::hashedJson($codes)]);
        $this->audit->log('auth.two_factor_codes_renewed', $userId, $request, 'user', $userId);

        return $codes;
    }

    public function disable(int $userId, ?Request $request = null, ?int $byUserId = null): void
    {
        $this->db->execute(
            'UPDATE users SET two_factor_secret = NULL, two_factor_confirmed_at = NULL, two_factor_recovery_codes = NULL, two_factor_last_step = NULL WHERE id = :id',
            ['id' => $userId],
        );
        $this->audit->log('auth.two_factor_disabled', $byUserId ?? $userId, $request, 'user', $userId);
    }

    // ---------- Connexion en deux temps ----------

    /** Mot de passe correct : la connexion attend le code (5 minutes au plus). */
    public function startPendingLogin(int $userId): void
    {
        $this->session->regenerate();
        $this->session->put(self::PENDING_KEY, ['user' => $userId, 'expires' => time() + self::PENDING_SECONDS, 'attempts' => 0]);
    }

    /** Compte en attente de son code, ou null (aucune connexion en cours, délai dépassé). */
    public function pendingUserId(): ?int
    {
        $pending = $this->session->get(self::PENDING_KEY);
        if (!is_array($pending) || ($pending['expires'] ?? 0) < time()) {
            $this->session->forget(self::PENDING_KEY);

            return null;
        }

        return (int) $pending['user'];
    }

    public function clearPendingLogin(): void
    {
        $this->session->forget(self::PENDING_KEY);
    }

    /**
     * Vérifie le code saisi à la connexion (code de l'application, ou code de secours à usage unique).
     * Après 5 erreurs, la connexion en attente est annulée : il faut ressaisir le mot de passe.
     */
    public function verifyPendingCode(string $code, ?Request $request = null): bool
    {
        $userId = $this->pendingUserId();
        if ($userId === null) {
            return false;
        }

        if ($this->verifyCode($userId, $code)) {
            $this->clearPendingLogin();

            return true;
        }

        $pending = (array) $this->session->get(self::PENDING_KEY);
        $pending['attempts'] = (int) ($pending['attempts'] ?? 0) + 1;
        $this->audit->log('auth.two_factor_failed', $userId, $request, 'user', $userId);
        if ($pending['attempts'] >= self::MAX_ATTEMPTS) {
            $this->clearPendingLogin();
        } else {
            $this->session->put(self::PENDING_KEY, $pending);
        }

        return false;
    }

    private function verifyCode(int $userId, string $code): bool
    {
        $row = $this->db->fetchOne(
            'SELECT two_factor_secret, two_factor_last_step, two_factor_recovery_codes::text AS codes FROM users WHERE id = :id AND two_factor_confirmed_at IS NOT NULL',
            ['id' => $userId],
        );
        if ($row === null) {
            return false;
        }

        $secret = $this->encrypter->decrypt((string) $row['two_factor_secret']);
        if ($secret !== null) {
            $step = Totp::verify($secret, $code, $this->clock->now()->getTimestamp(), $row['two_factor_last_step'] !== null ? (int) $row['two_factor_last_step'] : null);
            if ($step !== null) {
                $this->db->execute('UPDATE users SET two_factor_last_step = :s WHERE id = :id', ['id' => $userId, 's' => $step]);

                return true;
            }
        }

        // Code de secours : consommé dès qu'il est utilisé.
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
        $hashes = (array) json_decode((string) $row['codes'], true);
        $hash = hash('sha256', $normalized);
        foreach ($hashes as $i => $stored) {
            if (is_string($stored) && hash_equals($stored, $hash)) {
                unset($hashes[$i]);
                $this->db->execute('UPDATE users SET two_factor_recovery_codes = CAST(:c AS jsonb) WHERE id = :id', ['id' => $userId, 'c' => json_encode(array_values($hashes))]);

                return true;
            }
        }

        return false;
    }

    /** @return list<string> codes « XXXXX-XXXXX » */
    private static function newRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $raw = strtoupper(substr(Totp::base32Encode(random_bytes(7)), 0, 10));
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
        }

        return $codes;
    }

    /** @param list<string> $codes */
    private static function hashedJson(array $codes): string
    {
        return json_encode(array_map(static fn (string $c): string => hash('sha256', str_replace('-', '', $c)), $codes), JSON_THROW_ON_ERROR);
    }
}
