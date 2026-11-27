<?php

declare(strict_types=1);

namespace App\Core\Session;

use RuntimeException;

final class NativeSessionStorage implements SessionStorage
{
    /** @param array{name: string, path: string, idle_timeout: int, same_site: string} $config */
    public function __construct(private readonly array $config)
    {
    }

    public function start(bool $secure): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (!is_dir($this->config['path'])) {
            mkdir($this->config['path'], 0770, true);
        }

        session_save_path($this->config['path']);
        session_name($this->config['name']);

        $started = session_start([
            'use_strict_mode' => true,      // refuse les identifiants inventés par le client
            'use_only_cookies' => true,
            'use_trans_sid' => false,
            'cookie_httponly' => true,
            'cookie_secure' => $secure,
            'cookie_samesite' => $this->config['same_site'],
            'cookie_path' => '/',
            'cookie_lifetime' => 0,         // cookie de session (fermé avec le navigateur)
            'gc_maxlifetime' => max(1, $this->config['idle_timeout']) * 60,
        ]);

        if (!$started) {
            throw new RuntimeException('Impossible de démarrer la session.');
        }
    }

    public function isStarted(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    public function &data(): array
    {
        return $_SESSION;
    }

    public function regenerate(): void
    {
        if ($this->isStarted()) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        if (!$this->isStarted()) {
            return;
        }

        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $params['path'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?: 'Lax',
        ]);
        session_destroy();
    }

    public function id(): string
    {
        return (string) session_id();
    }
}
