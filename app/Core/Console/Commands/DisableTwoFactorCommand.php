<?php

declare(strict_types=1);

namespace App\Core\Console\Commands;

use App\Core\Console\Command;
use App\Repositories\UserRepository;
use App\Services\Auth\TwoFactorService;

/**
 * Retire la double authentification d'un compte (téléphone perdu et plus de code de secours).
 *   php bin/console user:disable-2fa --email=adresse@exemple.fr
 */
final class DisableTwoFactorCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TwoFactorService $twoFactor,
    ) {
    }

    public function name(): string
    {
        return 'user:disable-2fa';
    }

    public function description(): string
    {
        return 'Retire la double authentification d\'un compte (--email=…), en cas de téléphone perdu';
    }

    public function handle(array $arguments, array $options): int
    {
        $email = is_string($options['email'] ?? null) ? trim($options['email']) : '';
        $user = $email !== '' ? $this->users->findByEmail($email) : null;
        if ($user === null) {
            $this->output->error('Compte introuvable. Usage : php bin/console user:disable-2fa --email=adresse@exemple.fr');

            return 1;
        }

        $this->twoFactor->disable($user->id);
        $this->output->info("Double authentification retirée pour {$user->email}. Le compte se connecte avec son mot de passe ; il pourra la réactiver depuis son profil.");

        return 0;
    }
}
