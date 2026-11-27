<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;

final class LoginResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?User $user = null,
        public readonly int $lockedSeconds = 0,
        public readonly bool $twoFactorRequired = false,
    ) {
    }

    /** Mot de passe correct, mais la double authentification demande encore un code : session non ouverte. */
    public static function twoFactor(User $user): self
    {
        return new self(false, $user, 0, true);
    }

    public static function success(User $user): self
    {
        return new self(true, $user);
    }

    /** Identifiants incorrects, compte inactif ou sans accès : même réponse pour ne rien révéler. */
    public static function invalid(): self
    {
        return new self(false);
    }

    public static function locked(int $seconds): self
    {
        return new self(false, null, $seconds);
    }

    public function isLocked(): bool
    {
        return $this->lockedSeconds > 0;
    }
}
