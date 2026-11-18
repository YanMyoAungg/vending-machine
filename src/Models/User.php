<?php

declare(strict_types=1);

namespace App\Models;

final readonly class User
{
    public const ROLE_ADMIN = 'admin';

    public const ROLE_USER = 'user';

    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $passwordHash,
        public string $role,
        public bool $isActive,
        public string $createdAt,
    ) {
    }

    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['email'],
            (string) $row['password_hash'],
            (string) $row['role'],
            (bool) $row['is_active'],
            (string) $row['created_at'],
        );
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }
}
