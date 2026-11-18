<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Core\ValidationException;
use App\Models\User;
use App\Repositories\UserRepositoryInterface;
use App\Services\UserPolicy;
use PHPUnit\Framework\TestCase;

final class UserPolicyTest extends TestCase
{
    public function test_admin_cannot_demote_self(): void
    {
        $admin = $this->user(1, User::ROLE_ADMIN);
        $users = $this->createMock(UserRepositoryInterface::class);

        $this->expectException(ValidationException::class);

        (new UserPolicy($users))->assertCanChange($admin, $admin, User::ROLE_USER, true);
    }

    public function test_admin_cannot_deactivate_self(): void
    {
        $admin = $this->user(1, User::ROLE_ADMIN);
        $users = $this->createMock(UserRepositoryInterface::class);

        $this->expectException(ValidationException::class);

        (new UserPolicy($users))->assertCanChange($admin, $admin, User::ROLE_ADMIN, false);
    }

    public function test_last_active_admin_cannot_be_deactivated(): void
    {
        $actor = $this->user(1, User::ROLE_ADMIN);
        $other = $this->user(2, User::ROLE_ADMIN);
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('countActiveAdmins')->willReturn(1);

        $this->expectException(ValidationException::class);

        (new UserPolicy($users))->assertCanChange($actor, $other, User::ROLE_ADMIN, false);
    }

    public function test_last_active_admin_cannot_be_demoted(): void
    {
        $actor = $this->user(1, User::ROLE_ADMIN);
        $other = $this->user(2, User::ROLE_ADMIN);
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('countActiveAdmins')->willReturn(1);

        $this->expectException(ValidationException::class);

        (new UserPolicy($users))->assertCanChange($actor, $other, User::ROLE_USER, true);
    }

    public function test_a_regular_user_can_be_deactivated(): void
    {
        $actor = $this->user(1, User::ROLE_ADMIN);
        $other = $this->user(2, User::ROLE_USER);
        $users = $this->createMock(UserRepositoryInterface::class);

        (new UserPolicy($users))->assertCanChange($actor, $other, User::ROLE_USER, false);

        self::assertTrue(true);
    }

    public function test_editing_a_name_only_is_allowed(): void
    {
        $actor = $this->user(1, User::ROLE_ADMIN);
        $other = $this->user(2, User::ROLE_ADMIN);
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('countActiveAdmins')->willReturn(1);

        (new UserPolicy($users))->assertCanChange($actor, $other, User::ROLE_ADMIN, true);

        self::assertTrue(true);
    }

    private function user(int $id, string $role): User
    {
        return new User($id, 'User ' . $id, 'user' . $id . '@test', 'hash', $role, true, '2026-01-01');
    }
}
