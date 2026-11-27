<?php

declare(strict_types=1);

namespace App\Models;

final class User
{
    /**
     * @param list<string> $roles        codes des rôles (admin, staff, customer)
     * @param list<string> $permissions  codes des permissions cumulées des rôles
     */
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $passwordHash,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $phone,
        public readonly bool $isActive,
        public readonly ?string $lastLoginAt,
        public readonly array $roles = [],
        public readonly array $permissions = [],
        public readonly ?string $emailVerifiedAt = null,
    ) {
    }

    public function isCustomer(): bool
    {
        return $this->isActive && $this->hasRole('customer');
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerifiedAt !== null;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row, array $roles = [], array $permissions = []): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['email'],
            (string) $row['password_hash'],
            (string) $row['first_name'],
            (string) $row['last_name'],
            $row['phone'] !== null ? (string) $row['phone'] : null,
            (bool) $row['is_active'],
            $row['last_login_at'] !== null ? (string) $row['last_login_at'] : null,
            $roles,
            $permissions,
            isset($row['email_verified_at']) ? (string) $row['email_verified_at'] : null,
        );
    }

    public function fullName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /** Accès à /admin : administratrice ou collaborateur. */
    public function canAccessAdmin(): bool
    {
        return $this->isActive && ($this->hasRole('admin') || $this->hasRole('staff'));
    }
}
