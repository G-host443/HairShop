<?php

declare(strict_types=1);

namespace App\Core\Console\Commands;

use App\Core\Console\Command;
use App\Core\Security\PasswordHasher;
use App\Core\Validation\Validator;
use App\Repositories\UserRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\PasswordPolicy;

/**
 * Crée un compte d'administration.
 *
 *   php bin/console user:create-admin --email=garance@example.fr --first-name=Garance --last-name=Nom
 *
 * Sans --password, un mot de passe robuste est généré et affiché une seule fois.
 */
final class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly PasswordPolicy $policy,
        private readonly AuditLogger $audit,
    ) {
    }

    public function name(): string
    {
        return 'user:create-admin';
    }

    public function description(): string
    {
        return 'Crée un compte d\'administration (--email --first-name --last-name [--password] [--role=admin|staff])';
    }

    public function handle(array $arguments, array $options): int
    {
        $input = [
            'email' => is_string($options['email'] ?? null) ? $options['email'] : '',
            'first_name' => is_string($options['first-name'] ?? null) ? $options['first-name'] : '',
            'last_name' => is_string($options['last-name'] ?? null) ? $options['last-name'] : '',
            'role' => is_string($options['role'] ?? null) ? $options['role'] : 'admin',
        ];

        $validator = Validator::make($input, [
            'email' => 'required|email',
            'first_name' => 'required|max:100',
            'last_name' => 'required|max:100',
            'role' => 'required|in:admin,staff',
        ], ['email' => '--email', 'first_name' => '--first-name', 'last_name' => '--last-name', 'role' => '--role']);

        if ($validator->fails()) {
            foreach ($validator->errors() as $messages) {
                $this->output->error(implode(' ', $messages));
            }

            return 1;
        }

        if ($this->users->emailExists($input['email'])) {
            $this->output->error("Un compte existe déjà avec l'adresse {$input['email']}.");

            return 1;
        }

        $generated = !is_string($options['password'] ?? null);
        $password = $generated ? self::generatePassword() : (string) $options['password'];

        $problems = $this->policy->check($password, $input['email']);
        if ($problems !== []) {
            $this->output->error(implode(PHP_EOL, $problems));

            return 1;
        }

        $user = $this->users->create(
            $input['email'],
            $this->hasher->hash($password),
            trim($input['first_name']),
            trim($input['last_name']),
            [$input['role']],
        );
        $this->audit->log('user.created_from_console', null, null, 'user', $user->id, ['role' => $input['role']]);

        $this->output->info("Compte créé : {$user->fullName()} <{$user->email}> (rôle {$input['role']}).");
        if ($generated) {
            $this->output->line('Mot de passe (affiché une seule fois, à changer après la première connexion) :');
            $this->output->line('  ' . $password);
        }
        $this->output->line('Connexion : /admin/connexion');

        return 0;
    }

    /**
     * 20 caractères aléatoires garantissant minuscules, majuscules, chiffres et symboles.
     */
    public static function generatePassword(int $length = 20): string
    {
        $sets = ['abcdefghijkmnopqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789', '!@#$%*-_+?'];
        $all = implode('', $sets);

        $chars = array_map(static fn (string $set): string => $set[random_int(0, strlen($set) - 1)], $sets);
        while (count($chars) < $length) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }

        // Mélange de Fisher-Yates avec un générateur cryptographique.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
