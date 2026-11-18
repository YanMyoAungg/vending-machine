<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ValidationException;
use App\Models\User;
use App\Repositories\UserRepositoryInterface;

final class UserPolicy
{
    public function __construct(private readonly UserRepositoryInterface $users)
    {
    }

    public function assertCanChange(User $actor, User $target, string $newRole, bool $newActive): void
    {
        if ($actor->id === $target->id && ($newRole !== $target->role || !$newActive)) {
            throw new ValidationException([
                'user' => ['You cannot change your own role or deactivate your own account.'],
            ]);
        }

        $losesAdminAccess = $target->isAdmin()
            && $target->isActive
            && ($newRole !== User::ROLE_ADMIN || !$newActive);

        if ($losesAdminAccess && $this->users->countActiveAdmins() <= 1) {
            throw new ValidationException([
                'user' => ['At least one active admin is required.'],
            ]);
        }
    }
}
