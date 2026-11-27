<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Security\PasswordHasher;
use App\Repositories\UserRepository;
use Tests\Support\ArrayMailer;
use Tests\Support\Browser;

/**
 * Base des tests de l'administration : création de comptes et navigateur connecté.
 */
abstract class AdminTestCase extends DatabaseTestCase
{
    protected const PASSWORD = 'Tresses-2027!Knotless';

    protected ArrayMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailer = new ArrayMailer();
    }

    protected function browser(string $ip = '127.0.0.1'): Browser
    {
        return new Browser($this->db, $this->mailer, $ip);
    }

    protected function createUser(string $email, string $role = 'admin', string $password = self::PASSWORD): int
    {
        return (new UserRepository($this->db))->create($email, (new PasswordHasher())->hash($password), 'Garance', 'Test', [$role])->id;
    }

    /** Navigateur déjà connecté avec un nouveau compte du rôle demandé. */
    protected function loggedIn(string $role = 'admin', string $email = 'admin@example.test'): Browser
    {
        $this->createUser($email, $role);
        $browser = $this->browser();
        $browser->post('/admin/connexion', ['email' => $email, 'password' => self::PASSWORD]);

        return $browser;
    }
}
