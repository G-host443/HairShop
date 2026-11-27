<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * Hachage des mots de passe : Argon2id si disponible, sinon bcrypt (PASSWORD_DEFAULT).
 */
final class PasswordHasher
{
    private string|int|null $algorithm;

    public function __construct()
    {
        $this->algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    public function hash(string $password): string
    {
        return password_hash($password, $this->algorithm);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm);
    }

    /**
     * Vérification factice utilisée quand l'e-mail est inconnu, pour que le temps de réponse
     * ne révèle pas l'existence d'un compte.
     */
    public function verifyDummy(string $password): void
    {
        static $dummy = null;
        $dummy ??= $this->hash('mot-de-passe-factice-pour-temps-constant');
        password_verify($password, $dummy);
    }
}
