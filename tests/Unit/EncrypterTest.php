<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Security\Encrypter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EncrypterTest extends TestCase
{
    private function encrypter(string $key): Encrypter
    {
        return new Encrypter(new Config(['app' => ['key' => $key]]));
    }

    public function testRoundTripAndRandomNonce(): void
    {
        $e = $this->encrypter(str_repeat('a', 64));
        $secret = bin2hex(random_bytes(32));

        $first = $e->encrypt($secret);
        self::assertSame($secret, $e->decrypt($first));
        self::assertNotSame($first, $e->encrypt($secret), 'Deux chiffrements du même texte diffèrent.');
        self::assertStringNotContainsString($secret, base64_decode($first));
    }

    public function testTamperingOrWrongKeyIsRejected(): void
    {
        $e = $this->encrypter(str_repeat('a', 64));
        $payload = $e->encrypt('jeton');

        $raw = base64_decode($payload);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
        self::assertNull($e->decrypt(base64_encode($raw)), 'Texte altéré.');
        self::assertNull($this->encrypter(str_repeat('b', 64))->decrypt($payload), 'Autre clé.');
        self::assertNull($e->decrypt('pas du base64 !'));
    }

    public function testMissingKeyIsAnError(): void
    {
        $this->expectException(RuntimeException::class);
        $this->encrypter('');
    }
}
