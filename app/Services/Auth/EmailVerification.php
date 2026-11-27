<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Clock;
use App\Core\Config;
use App\Models\User;

/**
 * Lien de vérification d'adresse e-mail, signé (HMAC avec APP_KEY) : rien à stocker en base.
 * La signature couvre l'identifiant, l'adresse et l'échéance : changer d'adresse invalide les anciens liens.
 */
final class EmailVerification
{
    public const VALIDITY_HOURS = 48;

    public function __construct(
        private readonly Config $config,
        private readonly Clock $clock,
    ) {
    }

    public function expiry(): int
    {
        return $this->clock->now()->getTimestamp() + self::VALIDITY_HOURS * 3600;
    }

    public function token(User $user, int $expires): string
    {
        $payload = $user->id . '.' . $expires;

        return self::base64url($payload) . '.' . $this->signature($payload, $user->email);
    }

    /**
     * @return int|null identifiant de l'utilisateur si le lien est valide (le signataire est revérifié par l'appelant)
     */
    public function userIdFrom(string $token): ?int
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        $decoded = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if ($decoded === false || !preg_match('/^(\d+)\.(\d+)$/', $decoded, $m)) {
            return null;
        }

        return (int) $m[1];
    }

    public function isValid(string $token, User $user): bool
    {
        $parts = explode('.', $token);
        $decoded = count($parts) === 2 ? base64_decode(strtr($parts[0], '-_', '+/'), true) : false;
        if ($decoded === false || !preg_match('/^(\d+)\.(\d+)$/', $decoded, $m) || (int) $m[1] !== $user->id) {
            return false;
        }

        return (int) $m[2] >= $this->clock->now()->getTimestamp()
            && hash_equals($this->signature($decoded, $user->email), $parts[1]);
    }

    private function signature(string $payload, string $email): string
    {
        return hash_hmac('sha256', 'verify-email|' . $payload . '|' . mb_strtolower($email), (string) $this->config->get('app.key'));
    }

    private static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
