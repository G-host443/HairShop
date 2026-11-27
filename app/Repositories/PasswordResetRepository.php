<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class PasswordResetRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function create(int $userId, string $tokenHash, int $expiresMinutes): void
    {
        $this->db->execute(
            'INSERT INTO password_resets (user_id, token_hash, expires_at)
             VALUES (:user, :hash, now() + make_interval(mins => :minutes))',
            ['user' => $userId, 'hash' => $tokenHash, 'minutes' => $expiresMinutes],
        );
    }

    /**
     * Demande valide (non utilisée, non expirée) correspondant à l'empreinte du jeton.
     *
     * @return array{id: int, user_id: int}|null
     */
    public function findValid(string $tokenHash): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT id, user_id FROM password_resets
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > now()',
            ['hash' => $tokenHash],
        );

        return $row === null ? null : ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id']];
    }

    /**
     * Marque la demande comme utilisée ; renvoie false si elle l'a déjà été (requêtes concurrentes).
     */
    public function consume(int $id): bool
    {
        return $this->db->execute(
            'UPDATE password_resets SET used_at = now() WHERE id = :id AND used_at IS NULL',
            ['id' => $id],
        ) === 1;
    }

    /** Invalide toutes les demandes encore ouvertes d'un utilisateur. */
    public function invalidateForUser(int $userId): void
    {
        $this->db->execute(
            'UPDATE password_resets SET used_at = now() WHERE user_id = :user AND used_at IS NULL',
            ['user' => $userId],
        );
    }

    public function countRecentForUser(int $userId, int $minutes): int
    {
        return (int) $this->db->fetchValue(
            'SELECT count(*) FROM password_resets WHERE user_id = :user AND created_at > now() - make_interval(mins => :minutes)',
            ['user' => $userId, 'minutes' => $minutes],
        );
    }
}
