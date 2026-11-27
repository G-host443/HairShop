<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Router;
use App\Core\Security\PasswordHasher;
use App\Middleware\Authenticate;
use App\Middleware\RequirePermission;
use App\Repositories\UserRepository;
use DI\Container;
use Tests\Support\ArrayMailer;
use Tests\Support\Browser;

/**
 * Parcours complets de l'administration à travers le Kernel (middlewares, contrôleurs, vues, base).
 */
final class AdminAuthenticationTest extends DatabaseTestCase
{
    private const PASSWORD = 'Tresses-2027!Knotless';

    private ArrayMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailer = new ArrayMailer();
    }

    private function browser(string $ip = '127.0.0.1'): Browser
    {
        return new Browser($this->db, $this->mailer, $ip);
    }

    private function createUser(string $email, string $role = 'admin', string $password = self::PASSWORD): int
    {
        $users = new UserRepository($this->db);

        return $users->create($email, (new PasswordHasher())->hash($password), 'Garance', 'Test', [$role])->id;
    }

    private function login(Browser $browser, string $email, string $password = self::PASSWORD): \App\Core\Http\Response
    {
        return $browser->post('/admin/connexion', ['email' => $email, 'password' => $password]);
    }

    public function testGuestIsRedirectedToLoginAndBackAfterwards(): void
    {
        $this->createUser('admin@example.test');
        $browser = $this->browser();

        $response = $browser->get('/admin/profil/mot-de-passe');
        self::assertSame(302, $response->status());
        self::assertSame('/admin/connexion', $response->header('Location'));

        $response = $this->login($browser, 'admin@example.test');
        self::assertSame(303, $response->status());
        self::assertSame('/admin/profil/mot-de-passe', $response->header('Location'), 'Retour à la page demandée avant connexion.');

        $page = $browser->get('/admin');
        self::assertSame(200, $page->status());
        self::assertStringContainsString('Tableau de bord', $page->content());
    }

    public function testLoginPageRendersWithCsrfField(): void
    {
        $response = $this->browser()->get('/admin/connexion');

        self::assertSame(200, $response->status());
        self::assertMatchesRegularExpression('/name="_token" value="[a-f0-9]{64}"/', $response->content());
    }

    public function testPostWithoutCsrfTokenIsRejected(): void
    {
        $this->createUser('admin@example.test');
        $browser = $this->browser();

        $response = $browser->post('/admin/connexion', ['email' => 'admin@example.test', 'password' => self::PASSWORD], withCsrf: false);

        self::assertSame(419, $response->status());
    }

    public function testWrongPasswordShowsGenericErrorAndRecordsAttempt(): void
    {
        $this->createUser('admin@example.test');
        $browser = $this->browser();

        $response = $this->login($browser, 'admin@example.test', 'mauvais');

        self::assertSame(303, $response->status());
        self::assertSame(['email' => ['Adresse e-mail ou mot de passe incorrect.']], $browser->errors());
        self::assertSame(1, (int) $this->db->fetchValue("SELECT count(*) FROM login_attempts WHERE email = 'admin@example.test' AND NOT succeeded"));
        self::assertSame(302, $browser->get('/admin')->status(), 'Toujours non connecté.');
    }

    public function testUnknownEmailGetsTheSameMessage(): void
    {
        $browser = $this->browser();
        $this->login($browser, 'inconnue@example.test');

        self::assertSame(['email' => ['Adresse e-mail ou mot de passe incorrect.']], $browser->errors());
    }

    public function testAccountIsLockedAfterTooManyFailures(): void
    {
        $this->createUser('admin@example.test');
        $browser = $this->browser();

        for ($i = 0; $i < 5; $i++) {
            $this->login($browser, 'admin@example.test', 'mauvais-' . $i);
        }

        // Même avec le bon mot de passe, la connexion est refusée pendant le blocage.
        $this->login($browser, 'admin@example.test');

        self::assertStringStartsWith('Trop de tentatives de connexion', $browser->errors()['email'][0]);
        self::assertSame(302, $browser->get('/admin')->status());
    }

    public function testCustomerAccountCannotAccessAdmin(): void
    {
        $this->createUser('cliente@example.test', 'customer');
        $browser = $this->browser();

        $this->login($browser, 'cliente@example.test');

        self::assertSame(['email' => ['Adresse e-mail ou mot de passe incorrect.']], $browser->errors());
    }

    public function testInactiveAccountIsLoggedOutOnNextRequest(): void
    {
        $id = $this->createUser('admin@example.test');
        $browser = $this->browser();
        $this->login($browser, 'admin@example.test');
        self::assertSame(200, $browser->get('/admin')->status());

        (new UserRepository($this->db))->setActive($id, false);

        self::assertSame(302, $browser->get('/admin')->status());
    }

    public function testMissingPermissionReturns403(): void
    {
        $this->createUser('collab@example.test', 'staff');
        $browser = $this->browser()->configure(static function (Container $c): void {
            $c->get(Router::class)->get('/admin/test-reglages', static fn (): string => 'ok', null, [
                Authenticate::class,
                RequirePermission::class . ':settings.manage',
            ]);
        });

        $this->login($browser, 'collab@example.test');

        self::assertSame(200, $browser->get('/admin')->status(), 'Le collaborateur voit le tableau de bord.');
        self::assertSame(403, $browser->get('/admin/test-reglages')->status(), 'Mais pas les paramètres.');
    }

    public function testLogoutEndsTheSession(): void
    {
        $this->createUser('admin@example.test');
        $browser = $this->browser();
        $this->login($browser, 'admin@example.test');

        $response = $browser->post('/admin/deconnexion');

        self::assertSame(303, $response->status());
        self::assertSame('/admin/connexion', $response->header('Location'));
        self::assertSame(302, $browser->get('/admin')->status());
    }

    public function testChangingPasswordLogsOutOtherSessions(): void
    {
        $this->createUser('admin@example.test');
        $laptop = $this->browser();
        $phone = $this->browser('10.0.0.2');
        $this->login($laptop, 'admin@example.test');
        $this->login($phone, 'admin@example.test');

        $newPassword = 'Nouveau-Mot2Passe!Fulani';
        $response = $laptop->post('/admin/profil/mot-de-passe', [
            'current_password' => self::PASSWORD,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ]);

        self::assertSame(303, $response->status());
        self::assertSame([], $laptop->errors());
        self::assertSame(200, $laptop->get('/admin')->status(), 'La session qui a changé le mot de passe reste ouverte.');
        self::assertSame(302, $phone->get('/admin')->status(), 'Les autres sessions sont déconnectées.');

        $this->login($phone, 'admin@example.test', $newPassword);
        self::assertSame(200, $phone->get('/admin')->status());
    }

    public function testPasswordChangeEnforcesPolicyAndCurrentPassword(): void
    {
        $this->createUser('admin@example.test');
        $browser = $this->browser();
        $this->login($browser, 'admin@example.test');

        $browser->post('/admin/profil/mot-de-passe', ['current_password' => 'faux', 'password' => 'Abcdefgh-1234', 'password_confirmation' => 'Abcdefgh-1234']);
        self::assertArrayHasKey('current_password', $browser->errors());

        $browser->post('/admin/profil/mot-de-passe', ['current_password' => self::PASSWORD, 'password' => 'court', 'password_confirmation' => 'court']);
        self::assertArrayHasKey('password', $browser->errors());
    }

    public function testPasswordResetFlow(): void
    {
        $this->createUser('admin@example.test');
        $browser = $this->browser();

        $response = $browser->post('/admin/mot-de-passe-oublie', ['email' => 'admin@example.test']);
        self::assertSame('/admin/connexion', $response->header('Location'));
        self::assertNotNull($this->mailer->last());

        preg_match('#/admin/reinitialisation/([a-f0-9]{64})#', $this->mailer->last()->text, $m);
        self::assertNotEmpty($m, 'Le lien figure dans l\'e-mail.');
        $token = $m[1];

        // Le jeton n'est jamais stocké en clair.
        self::assertSame(0, (int) $this->db->fetchValue('SELECT count(*) FROM password_resets WHERE token_hash = :t', ['t' => $token]));

        self::assertSame(200, $browser->get("/admin/reinitialisation/{$token}")->status());

        $browser->post("/admin/reinitialisation/{$token}", ['password' => 'faible', 'password_confirmation' => 'faible']);
        self::assertArrayHasKey('password', $browser->errors());

        $newPassword = 'Bohemian-Curl!2027';
        $response = $browser->post("/admin/reinitialisation/{$token}", ['password' => $newPassword, 'password_confirmation' => $newPassword]);
        self::assertSame('/admin/connexion', $response->header('Location'));

        self::assertSame(410, $browser->get("/admin/reinitialisation/{$token}")->status(), 'Le lien ne sert qu\'une fois.');

        $this->login($browser, 'admin@example.test', $newPassword);
        self::assertSame(200, $browser->get('/admin')->status());
    }

    public function testPasswordResetDoesNotRevealUnknownAccounts(): void
    {
        $browser = $this->browser();

        $response = $browser->post('/admin/mot-de-passe-oublie', ['email' => 'personne@example.test']);

        self::assertSame('/admin/connexion', $response->header('Location'));
        self::assertStringStartsWith('Si cette adresse correspond à un compte', (string) $browser->flash('success'));
        self::assertSame([], $this->mailer->sent);
    }

    public function testExpiredResetLinkIsRefused(): void
    {
        $this->createUser('admin@example.test');
        $browser = $this->browser();
        $browser->post('/admin/mot-de-passe-oublie', ['email' => 'admin@example.test']);
        preg_match('#/admin/reinitialisation/([a-f0-9]{64})#', $this->mailer->last()->text, $m);

        $this->db->execute("UPDATE password_resets SET expires_at = now() - interval '1 minute'");

        self::assertSame(410, $browser->get("/admin/reinitialisation/{$m[1]}")->status());
    }

    public function testAuditLogRecordsLoginAndLogout(): void
    {
        $id = $this->createUser('admin@example.test');
        $browser = $this->browser();
        $this->login($browser, 'admin@example.test');
        $browser->post('/admin/deconnexion');

        $actions = array_column($this->db->fetchAll('SELECT action FROM audit_logs WHERE user_id = :id ORDER BY id', ['id' => $id]), 'action');

        self::assertSame(['auth.login', 'auth.logout'], $actions);
    }
}
