<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Core\ArraySession;
use App\Models\User;
use App\Repositories\UserRepositoryInterface;
use App\Services\AuthService;
use App\Services\NativePasswordHasher;
use PHPUnit\Framework\TestCase;

final class AuthServiceTest extends TestCase
{
    public function test_attempt_regenerates_session_and_stores_user(): void
    {
        $user = $this->user(active: true, hash: password_hash('secret', PASSWORD_DEFAULT));
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findByEmail')->with('ada@test')->willReturn($user);

        $session = new ArraySession();
        $before = $session->id();
        $auth = new AuthService($users, new NativePasswordHasher(), $session);

        self::assertTrue($auth->attempt('ada@test', 'secret'));
        self::assertSame($user->id, $session->get('auth_user_id'));
        self::assertNotSame($before, $session->id());
        self::assertSame($user->id, $auth->user()?->id);
    }

    public function test_inactive_user_cannot_authenticate(): void
    {
        $user = $this->user(active: false, hash: password_hash('secret', PASSWORD_DEFAULT));
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findByEmail')->willReturn($user);

        $auth = new AuthService($users, new NativePasswordHasher(), new ArraySession());

        self::assertFalse($auth->attempt('ada@test', 'secret'));
        self::assertNull($auth->verifyCredentials('ada@test', 'secret'));
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = $this->user(active: true, hash: password_hash('secret', PASSWORD_DEFAULT));
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findByEmail')->willReturn($user);

        $auth = new AuthService($users, new NativePasswordHasher(), new ArraySession());

        self::assertNull($auth->verifyCredentials('ada@test', 'wrong'));
    }

    public function test_existing_session_is_rejected_when_the_user_becomes_inactive(): void
    {
        $inactive = $this->user(active: false, hash: 'hash');
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->with(1)->willReturn($inactive);
        $session = new ArraySession();
        $session->set('auth_user_id', 1);
        $auth = new AuthService($users, new NativePasswordHasher(), $session);

        self::assertNull($auth->user());
        self::assertFalse($auth->check());
    }

    public function test_logout_destroys_the_session(): void
    {
        $session = new ArraySession();
        $session->set('auth_user_id', 1);
        $auth = new AuthService(
            $this->createMock(UserRepositoryInterface::class),
            new NativePasswordHasher(),
            $session
        );

        $auth->logout();

        self::assertNull($session->get('auth_user_id'));
        self::assertFalse($auth->check());
    }

    private function user(bool $active, string $hash): User
    {
        return new User(1, 'Ada', 'ada@test', $hash, User::ROLE_USER, $active, '2026-01-01 00:00:00');
    }
}
