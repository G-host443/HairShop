<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Session\Session;

/**
 * Jeton anti-CSRF stocké en session, comparé en temps constant.
 */
final class Csrf
{
    private const KEY = '_csrf_token';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::KEY);
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->put(self::KEY, $token);
        }

        return $token;
    }

    public function isValid(?string $token): bool
    {
        $expected = $this->session->get(self::KEY);

        return is_string($expected) && is_string($token) && $token !== '' && hash_equals($expected, $token);
    }

    /** Nouveau jeton (après connexion / déconnexion). */
    public function regenerate(): void
    {
        $this->session->put(self::KEY, bin2hex(random_bytes(32)));
    }
}
