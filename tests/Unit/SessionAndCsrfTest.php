<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Console\Commands\CreateAdminCommand;
use App\Core\Security\Csrf;
use App\Core\Session\ArraySessionStorage;
use App\Core\Session\Session;
use PHPUnit\Framework\TestCase;

final class SessionAndCsrfTest extends TestCase
{
    private Session $session;

    protected function setUp(): void
    {
        $this->session = new Session(new ArraySessionStorage());
        $this->session->start(false);
    }

    public function testFlashLivesForExactlyOneFollowingRequest(): void
    {
        $this->session->flash('success', 'Enregistré');

        $this->session->ageFlashData(); // requête suivante
        self::assertSame('Enregistré', $this->session->get('success'));

        $this->session->ageFlashData(); // encore après
        self::assertNull($this->session->get('success'));
    }

    public function testFlashedInputNeverKeepsPasswords(): void
    {
        $this->session->flashInput(['email' => 'a@b.fr', 'password' => 'secret', 'password_confirmation' => 'secret', '_token' => 'x']);

        self::assertSame('a@b.fr', $this->session->old('email'));
        self::assertNull($this->session->old('password'));
        self::assertNull($this->session->old('password_confirmation'));
        self::assertNull($this->session->old('_token'));
    }

    public function testCsrfTokenIsStableAndVerifiedInConstantTime(): void
    {
        $csrf = new Csrf($this->session);
        $token = $csrf->token();

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        self::assertSame($token, $csrf->token());
        self::assertTrue($csrf->isValid($token));
        self::assertFalse($csrf->isValid('faux'));
        self::assertFalse($csrf->isValid(null));

        $csrf->regenerate();
        self::assertFalse($csrf->isValid($token), 'Un jeton régénéré invalide l\'ancien.');
    }

    public function testGeneratedPasswordsAreStrong(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $password = CreateAdminCommand::generatePassword();
            self::assertSame(20, strlen($password));
            self::assertMatchesRegularExpression('/[a-z]/', $password);
            self::assertMatchesRegularExpression('/[A-Z]/', $password);
            self::assertMatchesRegularExpression('/\d/', $password);
            self::assertMatchesRegularExpression('/[^a-zA-Z\d]/', $password);
        }
    }
}
