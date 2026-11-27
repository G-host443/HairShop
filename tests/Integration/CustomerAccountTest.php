<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Database;
use App\Services\Mail\MailMessage;
use DI\Container;
use Tests\Support\Browser;

/**
 * Espace cliente (heure figée : lundi 1er mars 2027, 8 h).
 */
final class CustomerAccountTest extends AdminTestCase
{
    use CatalogFixtures;

    private const CUSTOMER_PASSWORD = 'Fulani-Tresses!2027';

    protected function database(): Database
    {
        return $this->db;
    }

    private function frozen(Browser $browser, string $at = '2027-03-01 08:00'): Browser
    {
        return $browser->configure(static fn (Container $c) => $c->get(Clock::class)->freeze($at));
    }

    private function register(Browser $browser, string $email = 'awa@example.test', array $extra = []): void
    {
        $browser->post('/compte/inscription', $extra + [
            'first_name' => 'Awa', 'last_name' => 'Diallo', 'email' => $email, 'phone' => '0611111111',
            'password' => self::CUSTOMER_PASSWORD, 'password_confirmation' => self::CUSTOMER_PASSWORD, 'consent' => '1',
        ]);
    }

    private function verificationLink(string $email): string
    {
        $mails = array_values(array_filter($this->mailer->sent, static fn (MailMessage $m) => $m->to === $email && str_contains($m->subject, 'Confirmez')));
        self::assertNotEmpty($mails, 'E-mail de vérification envoyé.');
        self::assertSame(1, preg_match('#(/compte/verifier/[A-Za-z0-9_-]+\.[a-f0-9]{64})#', end($mails)->text, $m));

        return $m[1];
    }

    /** Réservation en ligne (sans compte, ou avec la session du navigateur donné). */
    private function book(Browser $browser, array $f, string $date, string $email = 'awa@example.test', string $phone = '0622222222'): void
    {
        $browser->post('/reserver', [
            'service' => (string) $f['service'], 'variant' => (string) $f['variants']['M'], 'length' => (string) $f['lengths']['epaules'],
            'c' => '1', 'date' => $date, 'time' => '10:00',
            'first_name' => 'Awa', 'last_name' => 'Diallo', 'email' => $email, 'phone' => $phone, 'rules' => '1',
        ]);
    }

    private function userCustomerId(string $email): int
    {
        return (int) $this->db->fetchValue('SELECT c.id FROM customers c JOIN users u ON u.id = c.user_id WHERE u.email = :e', ['e' => $email]);
    }

    public function testRegistrationLogsInAndSendsVerification(): void
    {
        $browser = $this->frozen($this->browser());
        $this->register($browser);

        $user = $this->db->fetchOne("SELECT u.id, u.email_verified_at, r.code FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE u.email = 'awa@example.test'");
        self::assertSame('customer', $user['code']);
        self::assertNull($user['email_verified_at']);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT count(*) FROM customers WHERE user_id = :u', ['u' => $user['id']]));

        $page = $browser->get('/compte')->content();
        self::assertStringContainsString('Bonjour Awa', $page);
        self::assertStringContainsString('Confirmez votre adresse', $page);
        $this->verificationLink('awa@example.test');
    }

    public function testRegistrationValidation(): void
    {
        $this->createUser('pris@example.test', 'customer');
        $browser = $this->browser();

        $this->register($browser, 'pris@example.test');
        self::assertArrayHasKey('email', $browser->errors());
        $browser->get('/compte/inscription');

        $this->register($browser, 'nouvelle@example.test', ['password' => 'faible', 'password_confirmation' => 'faible']);
        self::assertArrayHasKey('password', $browser->errors());
        $browser->get('/compte/inscription');

        $this->register($browser, 'robot@example.test', ['website' => 'spam']);
        self::assertSame(0, (int) $this->db->fetchValue("SELECT count(*) FROM users WHERE email = 'robot@example.test'"));
    }

    public function testGuestBookingsAppearOnlyAfterEmailVerification(): void
    {
        $f = $this->matrixService();
        $this->book($this->frozen($this->browser('10.0.0.9')), $f, '2027-03-08');

        $browser = $this->frozen($this->browser());
        $this->register($browser);
        self::assertStringContainsString('Aucun rendez-vous à venir', $browser->get('/compte')->content(), 'Avant vérification : rien de visible.');

        $browser->get($this->verificationLink('awa@example.test'));
        $page = $browser->get('/compte')->content();
        self::assertStringContainsString('Knotless taille M', $page);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT count(*) FROM customers WHERE email = 'awa@example.test'"), 'La fiche invitée a été fusionnée dans celle du compte.');
    }

    public function testUnverifiedAccountCannotCaptureSomeoneElsesBookings(): void
    {
        $f = $this->matrixService();
        $impostor = $this->frozen($this->browser());
        $this->register($impostor, 'victime@example.test');

        // La vraie propriétaire de l'adresse réserve sans compte.
        $this->book($this->frozen($this->browser('10.0.0.9')), $f, '2027-03-08', 'victime@example.test', '0633333333');

        self::assertNotSame($this->userCustomerId('victime@example.test'), (int) $this->db->fetchValue('SELECT customer_id FROM bookings'));
        self::assertStringContainsString('Aucun rendez-vous à venir', $impostor->get('/compte')->content());
    }

    public function testInvalidOrExpiredVerificationLinks(): void
    {
        $browser = $this->frozen($this->browser());
        $this->register($browser);
        $link = $this->verificationLink('awa@example.test');

        self::assertSame(410, $browser->get(substr($link, 0, -1) . (str_ends_with($link, 'a') ? 'b' : 'a'))->status(), 'Signature altérée.');
        self::assertSame(410, $this->frozen($this->browser(), '2027-03-04 09:00')->get($link)->status(), 'Lien de plus de 48 h.');
        self::assertSame(303, $browser->get($link)->status());
        self::assertSame(303, $browser->get($link)->status(), 'Second clic sur le même lien : pas de message d\'erreur.');
        self::assertNotNull($this->db->fetchValue("SELECT email_verified_at FROM users WHERE email = 'awa@example.test'"));
    }

    public function testLoginSeparationBetweenCustomerAndAdmin(): void
    {
        $this->createUser('cliente@example.test', 'customer', self::CUSTOMER_PASSWORD);
        $this->createUser('admin@example.test', 'admin');
        $browser = $this->browser();

        $browser->post('/compte/connexion', ['email' => 'admin@example.test', 'password' => self::PASSWORD]);
        self::assertArrayHasKey('email', $browser->errors(), 'Un compte administrateur ne se connecte pas comme cliente.');
        $browser->get('/compte/connexion');

        $browser->post('/compte/connexion', ['email' => 'cliente@example.test', 'password' => self::CUSTOMER_PASSWORD]);
        self::assertSame(200, $browser->get('/compte')->status());
        self::assertSame(403, $browser->get('/admin')->status(), 'Pas d\'accès à l\'administration…');
        self::assertSame(200, $browser->get('/compte')->status(), '… mais la session cliente reste ouverte.');

        self::assertSame(302, $this->browser()->get('/compte')->status(), 'Espace protégé.');
    }

    public function testBookingWhileLoggedInGoesToTheAccountAndIsPrefilled(): void
    {
        $f = $this->matrixService();
        $this->createUser('cliente@example.test', 'customer', self::CUSTOMER_PASSWORD);
        $browser = $this->frozen($this->browser());
        $browser->post('/compte/connexion', ['email' => 'cliente@example.test', 'password' => self::CUSTOMER_PASSWORD]);

        $page = $browser->get("/reserver?service={$f['service']}&variant={$f['variants']['M']}&length={$f['lengths']['epaules']}&c=1&date=2027-03-08&time=10:00")->content();
        self::assertStringContainsString('value="cliente@example.test"', $page);
        self::assertStringContainsString('Session ouverte dans votre espace', $page);

        $this->book($browser, $f, '2027-03-08', 'cliente@example.test', '0644444444');
        self::assertSame($this->userCustomerId('cliente@example.test'), (int) $this->db->fetchValue('SELECT customer_id FROM bookings'));
        self::assertStringContainsString('Knotless taille M', $browser->get('/compte')->content());
    }

    public function testBookingDetailCancellationAndIsolation(): void
    {
        $f = $this->matrixService();
        $this->createUser('cliente@example.test', 'customer', self::CUSTOMER_PASSWORD);
        $this->createUser('autre@example.test', 'customer', self::CUSTOMER_PASSWORD);
        $mine = $this->frozen($this->browser());
        $mine->post('/compte/connexion', ['email' => 'cliente@example.test', 'password' => self::CUSTOMER_PASSWORD]);
        $this->book($mine, $f, '2027-03-08', 'cliente@example.test', '0644444444');
        $reference = (string) $this->db->fetchValue('SELECT reference FROM bookings');

        $other = $this->frozen($this->browser('10.0.0.3'));
        $other->post('/compte/connexion', ['email' => 'autre@example.test', 'password' => self::CUSTOMER_PASSWORD]);
        self::assertSame(404, $other->get("/compte/rendez-vous/{$reference}")->status(), 'Rendez-vous d\'une autre cliente : invisible.');
        $other->post("/compte/rendez-vous/{$reference}/annuler");
        self::assertSame('pending', $this->db->fetchValue('SELECT status FROM bookings'));

        self::assertSame(200, $mine->get("/compte/rendez-vous/{$reference}")->status());
        $mine->post("/compte/rendez-vous/{$reference}/annuler", ['reason' => 'Imprévu']);
        self::assertSame('cancelled', $this->db->fetchValue('SELECT status FROM bookings'));
    }

    public function testFavorites(): void
    {
        $f = $this->matrixService();
        $this->createUser('cliente@example.test', 'customer', self::CUSTOMER_PASSWORD);
        $browser = $this->browser();
        $browser->post('/compte/connexion', ['email' => 'cliente@example.test', 'password' => self::CUSTOMER_PASSWORD]);

        self::assertStringContainsString('Ajouter à mes favoris', $browser->get('/prestations/knotless-test')->content());
        $response = $browser->post("/compte/favoris/{$f['service']}", ['return_to' => '/prestations/knotless-test']);
        self::assertSame('/prestations/knotless-test', $response->header('Location'));
        self::assertStringContainsString('Dans mes favoris', $browser->get('/prestations/knotless-test')->content());
        self::assertStringContainsString('Knotless', $browser->get('/compte/favoris')->content());

        $browser->post("/compte/favoris/{$f['service']}");
        self::assertSame(0, (int) $this->db->fetchValue('SELECT count(*) FROM favorites'));
        self::assertSame(404, $browser->post('/compte/favoris/999999')->status());
    }

    public function testProfileUpdateEmailChangeAndPassword(): void
    {
        $this->createUser('pris@example.test', 'customer');
        $browser = $this->browser();
        $this->register($browser);
        $browser->get($this->verificationLink('awa@example.test'));

        $browser->post('/compte/profil', ['first_name' => 'Awa', 'last_name' => 'Diallo', 'email' => 'pris@example.test', 'phone' => '']);
        self::assertArrayHasKey('email', $browser->errors(), 'Adresse d\'un autre compte refusée.');
        $browser->get('/compte/profil');

        $this->mailer->sent = [];
        $browser->post('/compte/profil', ['first_name' => 'Awa-Marie', 'last_name' => 'Diallo', 'email' => 'awa.new@example.test', 'phone' => '0600000000', 'marketing' => '1']);
        $row = $this->db->fetchOne("SELECT u.email_verified_at, c.first_name, c.email, c.marketing_consent FROM users u JOIN customers c ON c.user_id = u.id WHERE u.email = 'awa.new@example.test'");
        self::assertNull($row['email_verified_at'], 'Nouvelle adresse à confirmer.');
        self::assertSame(['Awa-Marie', 'awa.new@example.test', true], [$row['first_name'], $row['email'], (bool) $row['marketing_consent']]);
        $this->verificationLink('awa.new@example.test');

        $browser->post('/compte/mot-de-passe', ['current_password' => 'faux', 'password' => 'Autre-Mot2Passe!', 'password_confirmation' => 'Autre-Mot2Passe!']);
        self::assertArrayHasKey('current_password', $browser->errors());
        $browser->get('/compte/profil');
        $browser->post('/compte/mot-de-passe', ['current_password' => self::CUSTOMER_PASSWORD, 'password' => 'Autre-Mot2Passe!', 'password_confirmation' => 'Autre-Mot2Passe!']);
        self::assertSame([], $browser->errors());

        $fresh = $this->browser('10.0.0.5');
        $fresh->post('/compte/connexion', ['email' => 'awa.new@example.test', 'password' => 'Autre-Mot2Passe!']);
        self::assertSame(200, $fresh->get('/compte')->status());
    }

    public function testCustomerPasswordReset(): void
    {
        $this->createUser('cliente@example.test', 'customer', self::CUSTOMER_PASSWORD);
        $this->createUser('admin@example.test', 'admin');
        $browser = $this->browser();

        $browser->post('/compte/mot-de-passe-oublie', ['email' => 'admin@example.test']);
        self::assertSame([], $this->mailer->sent, 'Le formulaire client ne réinitialise pas un compte administrateur.');

        $browser->post('/compte/mot-de-passe-oublie', ['email' => 'cliente@example.test']);
        self::assertSame(1, preg_match('#(/compte/reinitialisation/[a-f0-9]{64})#', $this->mailer->last()->text, $m));

        $browser->post($m[1], ['password' => 'Nouvelle-Coiffure!42', 'password_confirmation' => 'Nouvelle-Coiffure!42']);
        $browser->post('/compte/connexion', ['email' => 'cliente@example.test', 'password' => 'Nouvelle-Coiffure!42']);
        self::assertSame(200, $browser->get('/compte')->status());
        self::assertNotNull($this->db->fetchValue("SELECT email_verified_at FROM users WHERE email = 'cliente@example.test'"), 'Recevoir le lien prouve l\'adresse.');
    }

    public function testExportAndDeletion(): void
    {
        $f = $this->matrixService();
        $browser = $this->frozen($this->browser());
        $this->register($browser);
        $this->book($browser, $f, '2027-03-08');

        $export = $browser->get('/compte/export');
        self::assertStringContainsString('attachment;', (string) $export->header('Content-Disposition'));
        $data = json_decode($export->content(), true);
        self::assertSame('awa@example.test', $data['compte']['email']);
        self::assertCount(1, $data['rendez_vous']);

        $browser->post('/compte/supprimer', ['password' => 'faux']);
        self::assertArrayHasKey('delete_password', $browser->errors());
        $browser->get('/compte/profil');

        $browser->post('/compte/supprimer', ['password' => self::CUSTOMER_PASSWORD]);
        self::assertStringContainsString('rendez-vous à venir', implode(' ', $browser->errors()['delete_password'] ?? []));
        $browser->get('/compte/profil');

        $reference = (string) $this->db->fetchValue('SELECT reference FROM bookings');
        $browser->post("/compte/rendez-vous/{$reference}/annuler");
        $response = $browser->post('/compte/supprimer', ['password' => self::CUSTOMER_PASSWORD]);

        self::assertSame('/', $response->header('Location'));
        self::assertSame(0, (int) $this->db->fetchValue("SELECT count(*) FROM users WHERE email = 'awa@example.test'"));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT count(*) FROM bookings'), 'Historique conservé pour le salon.');
        self::assertNull($this->db->fetchValue('SELECT c.user_id FROM bookings b JOIN customers c ON c.id = b.customer_id'));
        self::assertSame(302, $browser->get('/compte')->status(), 'Session fermée.');
    }
}
