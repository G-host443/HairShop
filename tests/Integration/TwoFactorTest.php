<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Services\Auth\Totp;
use DI\Container;
use Tests\Support\Browser;

/**
 * Double authentification : désactivée par défaut, activation compte par compte, connexion en deux temps,
 * codes de secours, protection contre le rejeu et les essais répétés.
 */
final class TwoFactorTest extends AdminTestCase
{
    private const NOW = '2027-03-01 10:00:00';

    private function frozen(Browser $browser, string $at = self::NOW): Browser
    {
        return $browser->configure(static fn (Container $c) => $c->get(Clock::class)->freeze($at));
    }

    private function enableSetting(bool $on = true): void
    {
        $this->db->execute("UPDATE site_settings SET value = CAST(:v AS jsonb) WHERE key = 'security.two_factor_enabled'", ['v' => json_encode($on)]);
    }

    private static function codeAt(string $secret, string $at): string
    {
        return Totp::code($secret, Totp::step((new \DateTimeImmutable($at, new \DateTimeZone('Europe/Paris')))->getTimestamp()));
    }

    /** Active la double authentification pour le compte connecté et renvoie [clé, codes de secours]. */
    private function enroll(Browser $admin): array
    {
        $page = $admin->get('/admin/profil/double-authentification?configurer=1')->content();
        self::assertSame(1, preg_match('#class="admin-totp-key">([A-Z2-7 ]+)</code>#', $page, $m));
        $secret = str_replace(' ', '', $m[1]);
        self::assertStringContainsString('otpauth://totp/', html_entity_decode($page));

        $admin->post('/admin/profil/double-authentification', ['code' => self::codeAt($secret, self::NOW)]);
        self::assertSame([], $admin->errors());
        $codesPage = $admin->get('/admin/profil/double-authentification')->content();
        preg_match_all('#<li><code>([A-Z2-7]{5}-[A-Z2-7]{5})</code></li>#', $codesPage, $codes);
        self::assertCount(8, $codes[1], 'Huit codes de secours affichés une fois.');
        self::assertStringNotContainsString($codes[1][0], $admin->get('/admin/profil/double-authentification')->content(), 'Plus jamais réaffichés.');

        return [$secret, $codes[1]];
    }

    private function login(string $ip = '10.0.0.40'): Browser
    {
        $browser = $this->frozen($this->browser($ip));
        $browser->post('/admin/connexion', ['email' => 'admin@example.test', 'password' => self::PASSWORD]);

        return $browser;
    }

    public function testDisabledByDefault(): void
    {
        self::assertSame('false', $this->db->fetchValue("SELECT value::text FROM site_settings WHERE key = 'security.two_factor_enabled'"), 'Réglage à « non » après installation.');

        $admin = $this->frozen($this->loggedIn());
        self::assertSame(200, $admin->get('/admin')->status(), 'Connexion directe, sans code.');
        $page = $admin->get('/admin/profil/double-authentification')->content();
        self::assertStringContainsString('n\'est pas activée sur ce site', $page);
        self::assertStringNotContainsString('admin-totp-key', $admin->get('/admin/profil/double-authentification?configurer=1')->content(), 'Configuration impossible tant que le réglage est désactivé.');
    }

    public function testEnrollmentAndTwoStepLogin(): void
    {
        $this->enableSetting();
        [$secret] = $this->enroll($this->frozen($this->loggedIn()));
        self::assertNotNull($this->db->fetchValue('SELECT two_factor_confirmed_at FROM users'));
        self::assertStringNotContainsString($secret, (string) $this->db->fetchValue('SELECT two_factor_secret FROM users'), 'Clé stockée chiffrée.');

        // Mot de passe seul : pas de session.
        $browser = $this->login();
        self::assertSame(302, $browser->get('/admin')->status(), 'Pas encore connectée.');
        self::assertSame(200, $browser->get('/admin/connexion/verification')->status());

        $browser->post('/admin/connexion/verification', ['code' => '000000']);
        self::assertArrayHasKey('code', $browser->errors());

        $later = '2027-03-01 10:01:00';
        $ok = $this->frozen($browser, $later)->post('/admin/connexion/verification', ['code' => self::codeAt($secret, $later)]);
        self::assertSame(303, $ok->status());
        self::assertSame(200, $browser->get('/admin')->status(), 'Connectée après le code.');

        // Le même code ne sert pas deux fois (rejeu).
        $again = $this->login('10.0.0.41');
        $this->frozen($again, $later)->post('/admin/connexion/verification', ['code' => self::codeAt($secret, $later)]);
        self::assertArrayHasKey('code', $again->errors());
    }

    public function testRecoveryCodesAndDisabling(): void
    {
        $this->enableSetting();
        $admin = $this->frozen($this->loggedIn());
        [, $codes] = $this->enroll($admin);

        $browser = $this->login();
        $browser->post('/admin/connexion/verification', ['code' => strtolower($codes[0])]);
        self::assertSame(200, $browser->get('/admin')->status(), 'Code de secours accepté (casse ignorée).');

        $other = $this->login('10.0.0.42');
        $other->post('/admin/connexion/verification', ['code' => $codes[0]]);
        self::assertArrayHasKey('code', $other->errors(), 'Code de secours à usage unique.');
        self::assertStringContainsString('restants : <strong>7</strong>', $admin->get('/admin/profil/double-authentification')->content());

        $admin->post('/admin/profil/double-authentification/desactiver', ['password' => 'faux']);
        self::assertArrayHasKey('password', $admin->errors());
        $admin->get('/admin');
        $admin->post('/admin/profil/double-authentification/desactiver', ['password' => self::PASSWORD]);
        self::assertNull($this->db->fetchValue('SELECT two_factor_secret FROM users'));
        self::assertSame(200, $this->login('10.0.0.43')->get('/admin')->status(), 'Connexion directe à nouveau.');
    }

    public function testGlobalSwitchOffBypassesCodeAndTooManyErrorsRestartLogin(): void
    {
        $this->enableSetting();
        $this->enroll($this->frozen($this->loggedIn()));

        $browser = $this->login();
        for ($i = 0; $i < 5; $i++) {
            $browser->post('/admin/connexion/verification', ['code' => '123456']);
        }
        self::assertSame(303, $browser->get('/admin/connexion/verification')->status(), 'Après 5 erreurs : retour à la saisie du mot de passe.');

        $this->enableSetting(false);
        self::assertSame(200, $this->login('10.0.0.44')->get('/admin')->status(), 'Réglage désactivé : plus aucun code demandé.');
    }
}
