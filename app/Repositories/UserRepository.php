<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Models\User;

final class UserRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function findById(int $id): ?User
    {
        $row = $this->db->fetchOne('SELECT * FROM users WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByEmail(string $email): ?User
    {
        $row = $this->db->fetchOne('SELECT * FROM users WHERE email = :email', ['email' => trim($email)]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @param list<string> $roles
     */
    public function create(string $email, string $passwordHash, string $firstName, string $lastName, array $roles, ?string $phone = null): User
    {
        return $this->db->transaction(function () use ($email, $passwordHash, $firstName, $lastName, $roles, $phone): User {
            $id = (int) $this->db->fetchValue(
                'INSERT INTO users (email, password_hash, first_name, last_name, phone)
                 VALUES (:email, :hash, :first, :last, :phone) RETURNING id',
                ['email' => trim($email), 'hash' => $passwordHash, 'first' => $firstName, 'last' => $lastName, 'phone' => $phone],
            );

            foreach ($roles as $role) {
                $this->db->execute(
                    'INSERT INTO user_roles (user_id, role_id) SELECT :user, id FROM roles WHERE code = :role ON CONFLICT DO NOTHING',
                    ['user' => $id, 'role' => $role],
                );
            }

            return $this->findById($id) ?? throw new \RuntimeException('Utilisateur introuvable après création.');
        });
    }

    public function updatePassword(int $userId, string $passwordHash): void
    {
        $this->db->execute('UPDATE users SET password_hash = :hash WHERE id = :id', ['hash' => $passwordHash, 'id' => $userId]);
    }

    public function touchLastLogin(int $userId): void
    {
        $this->db->execute('UPDATE users SET last_login_at = now() WHERE id = :id', ['id' => $userId]);
    }

    public function setActive(int $userId, bool $active): void
    {
        $this->db->execute('UPDATE users SET is_active = :active WHERE id = :id', ['active' => $active, 'id' => $userId]);
    }

    public function markEmailVerified(int $userId): void
    {
        $this->db->execute('UPDATE users SET email_verified_at = COALESCE(email_verified_at, now()) WHERE id = :id', ['id' => $userId]);
    }

    /** Met à jour l'identité ; un changement d'e-mail oblige à le vérifier de nouveau. */
    public function updateIdentity(int $userId, string $firstName, string $lastName, string $email, ?string $phone): void
    {
        $this->db->execute(
            'UPDATE users SET first_name = :f, last_name = :l, phone = :p,
                    email_verified_at = CASE WHEN email = CAST(:e AS citext) THEN email_verified_at END,
                    email = :e2
             WHERE id = :id',
            ['f' => $firstName, 'l' => $lastName, 'p' => $phone, 'e' => $email, 'e2' => $email, 'id' => $userId],
        );
    }

    public function emailTakenByOther(string $email, int $userId): bool
    {
        return (bool) $this->db->fetchValue('SELECT EXISTS (SELECT 1 FROM users WHERE email = :e AND id <> :id)', ['e' => trim($email), 'id' => $userId]);
    }

    public function delete(int $userId): void
    {
        $this->db->execute('DELETE FROM users WHERE id = :id', ['id' => $userId]);
    }

    public function emailExists(string $email): bool
    {
        return (bool) $this->db->fetchValue('SELECT EXISTS (SELECT 1 FROM users WHERE email = :email)', ['email' => trim($email)]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): User
    {
        $roles = array_column($this->db->fetchAll(
            'SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :id ORDER BY r.code',
            ['id' => (int) $row['id']],
        ), 'code');

        $permissions = array_column($this->db->fetchAll(
            'SELECT DISTINCT p.code
             FROM user_roles ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = :id
             ORDER BY p.code',
            ['id' => (int) $row['id']],
        ), 'code');

        return User::fromRow($row, $roles, $permissions);
    }
}
