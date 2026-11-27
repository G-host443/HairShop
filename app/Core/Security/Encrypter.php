<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Config;
use RuntimeException;

/**
 * Chiffrement symétrique authentifié (libsodium, XSalsa20-Poly1305) avec une clé dérivée de APP_KEY.
 * Un texte modifié ou chiffré avec une autre clé est rejeté.
 */
final class Encrypter
{
    private string $key;

    public function __construct(Config $config)
    {
        $appKey = (string) $config->get('app.key');
        if (strlen($appKey) < 32) {
            throw new RuntimeException('APP_KEY manquante ou trop courte : lancez « php bin/console key:generate ».');
        }
        // Clé dédiée à cet usage, dérivée de APP_KEY.
        $this->key = sodium_crypto_generichash('encrypter:' . $appKey, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    /** @return string|null null si le texte est invalide, altéré ou chiffré avec une autre clé */
    public function decrypt(string $payload): ?string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );

        return $plain === false ? null : $plain;
    }
}
