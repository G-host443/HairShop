<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class LoginAttemptRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function record(?string $email, string $ip, bool $succeeded): void
    {
        $this->db->execute(
            'INSERT INTO login_attempts (email, ip, succeeded) VALUES (:email, CAST(:ip AS inet), :ok)',
            ['email' => $email !== null && $email !== '' ? mb_substr(trim($email), 0, 190) : null, 'ip' => $this->normalizeIp($ip), 'ok' => $succeeded],
        );
    }

    /**
     * Échecs depuis la dernière connexion réussie, dans la fenêtre donnée.
     */
    public function recentFailuresForEmail(string $email, int $minutes): int
    {
        return (int) $this->db->fetchValue(
            "SELECT count(*) FROM login_attempts
             WHERE email = :email AND NOT succeeded
               AND attempted_at > now() - make_interval(mins => :minutes)
               AND attempted_at > COALESCE(
                   (SELECT max(attempted_at) FROM login_attempts WHERE email = :email2 AND succeeded),
                   '-infinity'::timestamptz)",
            ['email' => trim($email), 'email2' => trim($email), 'minutes' => $minutes],
        );
    }

    public function recentFailuresForIp(string $ip, int $minutes): int
    {
        return (int) $this->db->fetchValue(
            'SELECT count(*) FROM login_attempts
             WHERE ip = CAST(:ip AS inet) AND NOT succeeded AND attempted_at > now() - make_interval(mins => :minutes)',
            ['ip' => $this->normalizeIp($ip), 'minutes' => $minutes],
        );
    }

    /** Secondes restantes avant de pouvoir réessayer (0 si non bloqué). */
    public function secondsUntilOldestFailureExpires(string $email, int $minutes): int
    {
        $value = $this->db->fetchValue(
            "SELECT EXTRACT(EPOCH FROM (min(attempted_at) + make_interval(mins => :minutes) - now()))::int
             FROM login_attempts
             WHERE email = :email AND NOT succeeded AND attempted_at > now() - make_interval(mins => :minutes2)",
            ['email' => trim($email), 'minutes' => $minutes, 'minutes2' => $minutes],
        );

        return max(0, (int) $value);
    }

    /** Purge des tentatives anciennes (appelée par une tâche planifiée). */
    public function purgeOlderThan(int $days): int
    {
        return $this->db->execute(
            'DELETE FROM login_attempts WHERE attempted_at < now() - make_interval(days => :days)',
            ['days' => $days],
        );
    }

    private function normalizeIp(string $ip): string
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }
}
