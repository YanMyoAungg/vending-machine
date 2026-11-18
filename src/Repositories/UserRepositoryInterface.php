<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\PaginatedResult;
use App\Models\User;

interface UserRepositoryInterface
{
    /** @return PaginatedResult<User> */
    public function paginate(int $page, int $perPage, string $search = ''): PaginatedResult;

    public function findById(int $id): ?User;

    public function findByEmail(string $email): ?User;

    /**
     * @param array{name: string, email: string, password_hash: string, role: string, is_active: int|bool} $data
     */
    public function create(array $data): User;

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): User;

    public function softDelete(int $id): void;

    public function restore(int $id): void;

    public function countActiveAdmins(): int;

    public function emailExists(string $email, ?int $ignoreId = null): bool;
}
