<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Config;
use App\Services\Settings\SettingsService;

/**
 * Règles de robustesse des mots de passe (longueur, variété, pas de lien avec l'e-mail).
 */
final class PasswordPolicy
{
    /** Mots de passe trop courants, refusés même s'ils respectent la longueur. */
    private const COMMON = ['motdepasse123', 'password1234', 'azertyuiop12', '123456789012', 'qwertyuiop12', 'garance12345'];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly Config $config,
    ) {
    }

    public function minLength(): int
    {
        return max(8, $this->settings->int('security.password_min_length', (int) $this->config->get('security.password.min_length', 12)));
    }

    /**
     * @return list<string> messages d'erreur (vide = mot de passe accepté)
     */
    public function check(string $password, ?string $email = null): array
    {
        $errors = [];
        $length = mb_strlen($password);
        $max = (int) $this->config->get('security.password.max_length', 128);

        if ($length < $this->minLength()) {
            $errors[] = "Le mot de passe doit contenir au moins {$this->minLength()} caractères.";
        }
        if ($length > $max) {
            $errors[] = "Le mot de passe ne doit pas dépasser {$max} caractères.";
        }

        $classes = (int) preg_match('/[a-z]/u', $password)
            + (int) preg_match('/[A-Z]/u', $password)
            + (int) preg_match('/\d/u', $password)
            + (int) preg_match('/[^a-zA-Z\d]/u', $password);
        if ($classes < 3) {
            $errors[] = 'Le mot de passe doit mélanger au moins trois types de caractères : minuscules, majuscules, chiffres, symboles.';
        }

        $lower = mb_strtolower($password);
        if (in_array($lower, self::COMMON, true)) {
            $errors[] = 'Ce mot de passe est trop courant.';
        }
        if ($email !== null && $email !== '') {
            $local = mb_strtolower(explode('@', $email)[0]);
            if (mb_strlen($local) >= 4 && str_contains($lower, $local)) {
                $errors[] = 'Le mot de passe ne doit pas contenir votre adresse e-mail.';
            }
        }

        return $errors;
    }
}
