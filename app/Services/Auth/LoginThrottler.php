<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Config;
use App\Repositories\LoginAttemptRepository;
use App\Services\Settings\SettingsService;

/**
 * Limitation des tentatives de connexion, par e-mail et par adresse IP.
 */
final class LoginThrottler
{
    public function __construct(
        private readonly LoginAttemptRepository $attempts,
        private readonly SettingsService $settings,
        private readonly Config $config,
    ) {
    }

    /**
     * @return int secondes d'attente restantes (0 = tentative autorisée)
     */
    public function secondsLocked(string $email, string $ip): int
    {
        $window = $this->windowMinutes();

        if ($email !== '' && $this->attempts->recentFailuresForEmail($email, $window) >= $this->maxPerEmail()) {
            return max(60, $this->attempts->secondsUntilOldestFailureExpires($email, $window));
        }

        if ($this->attempts->recentFailuresForIp($ip, $window) >= $this->maxPerIp()) {
            return $window * 60;
        }

        return 0;
    }

    public function recordFailure(string $email, string $ip): void
    {
        $this->attempts->record($email, $ip, false);
    }

    public function recordSuccess(string $email, string $ip): void
    {
        $this->attempts->record($email, $ip, true);
    }

    private function windowMinutes(): int
    {
        return max(1, $this->settings->int('security.lockout_minutes', (int) $this->config->get('security.login.lockout_minutes', 15)));
    }

    private function maxPerEmail(): int
    {
        return max(1, $this->settings->int('security.max_login_attempts', (int) $this->config->get('security.login.max_attempts', 5)));
    }

    private function maxPerIp(): int
    {
        return max($this->maxPerEmail(), (int) $this->config->get('security.login.max_attempts_per_ip', 20));
    }
}
