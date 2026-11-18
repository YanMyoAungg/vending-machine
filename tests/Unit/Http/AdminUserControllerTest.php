<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Core\ArraySession;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Router;
use App\Core\View;
use App\Http\Controllers\AdminUserController;
use App\Models\PaginatedResult;
use App\Models\User;
use App\Repositories\UserRepositoryInterface;
use App\Services\NativePasswordHasher;
use App\Services\PasswordHasherInterface;
use App\Services\UserPolicy;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AdminUserControllerTest extends TestCase
{
    private UserRepositoryInterface&MockObject $users;
    private PasswordHasherInterface&MockObject $hasher;
    private ArraySession $session;
    private User $admin;

    protected function setUp(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->hasher = $this->createMock(PasswordHasherInterface::class);
        $this->session = new ArraySession();
        $this->admin = new User(1, 'Admin', 'admin@vending.test', 'hash', User::ROLE_ADMIN, true, '2026-01-01');
    }

    public function test_index_lists_users_without_password_hashes(): void
    {
        $this->users->method('paginate')->with(1, 20, '')->willReturn(
            new PaginatedResult([$this->admin], 1, 1, 20)
        );

        $body = $this->controller()->index(new Request('GET', '/admin/users'))->body();

        self::assertStringContainsString('admin@vending.test', $body);
        self::assertStringContainsString('Admin', $body);
        self::assertStringNotContainsString('hash', $body);
    }

    public function test_store_hashes_the_password_and_lowercases_email(): void
    {
        $this->users->method('emailExists')->willReturn(false);
        $this->hasher->expects(self::once())->method('hash')->with('secret123')->willReturn('hashed');
        $this->users->expects(self::once())->method('create')->with(self::callback(
            static fn (array $data): bool => $data['email'] === 'new@example.test' && $data['password_hash'] === 'hashed'
        ))->willReturn($this->admin);

        $response = $this->controller()->store($this->request([
            'name' => 'New User',
            'email' => 'NEW@Example.test',
            'role' => User::ROLE_USER,
            'password' => 'secret123',
            'is_active' => '1',
        ]));

        self::assertSame(302, $response->status());
        self::assertSame('/admin/users', $response->headers()['Location']);
    }

    public function test_store_rejects_duplicate_email(): void
    {
        $this->users->method('emailExists')->willReturn(true);
        $this->users->expects(self::never())->method('create');

        $response = $this->controller()->store($this->request([
            'name' => 'New User',
            'email' => 'admin@vending.test',
            'role' => User::ROLE_USER,
            'password' => 'secret123',
            'is_active' => '1',
        ]));

        self::assertSame(422, $response->status());
        self::assertStringContainsString('already registered', $response->body());
    }

    public function test_update_keeps_hash_when_password_is_blank(): void
    {
        $target = $this->user(2, User::ROLE_USER);
        $this->users->method('findById')->with(2)->willReturn($target);
        $this->users->method('emailExists')->willReturn(false);
        $this->hasher->expects(self::never())->method('hash');
        $this->users->expects(self::once())->method('update')->with(2, self::callback(
            static fn (array $data): bool => !array_key_exists('password_hash', $data)
        ))->willReturn($target);

        $response = $this->controller()->update(2, $this->request([
            'name' => 'Updated',
            'email' => 'updated@example.test',
            'role' => User::ROLE_USER,
            'password' => '',
            'is_active' => '1',
        ]));

        self::assertSame(302, $response->status());
    }

    public function test_update_hashes_a_new_password(): void
    {
        $target = $this->user(2, User::ROLE_USER);
        $this->users->method('findById')->willReturn($target);
        $this->users->method('emailExists')->willReturn(false);
        $this->hasher->expects(self::once())->method('hash')->with('newsecret')->willReturn('new-hash');
        $this->users->expects(self::once())->method('update')->with(2, self::callback(
            static fn (array $data): bool => ($data['password_hash'] ?? null) === 'new-hash'
        ))->willReturn($target);

        $this->controller()->update(2, $this->request([
            'name' => 'Updated',
            'email' => 'updated@example.test',
            'role' => User::ROLE_USER,
            'password' => 'newsecret',
            'is_active' => '1',
        ]));

        self::assertTrue(true);
    }

    public function test_admin_cannot_deactivate_self(): void
    {
        $this->users->method('findById')->with(1)->willReturn($this->admin);
        $this->users->method('emailExists')->willReturn(false);
        $this->users->expects(self::never())->method('update');

        $response = $this->controller()->update(1, $this->request([
            'name' => 'Admin',
            'email' => 'admin@vending.test',
            'role' => User::ROLE_ADMIN,
            'password' => '',
            'is_active' => '',
        ]));

        self::assertSame(422, $response->status());
        self::assertStringContainsString('your own', $response->body());
    }

    public function test_destroy_soft_deletes_a_regular_user(): void
    {
        $target = $this->user(2, User::ROLE_USER);
        $this->users->method('findById')->with(2)->willReturn($target);
        $this->users->expects(self::once())->method('softDelete')->with(2);

        $response = $this->controller()->destroy(2, $this->request([]));

        self::assertSame(302, $response->status());
    }

    public function test_destroy_restores_an_inactive_user(): void
    {
        $target = $this->user(2, User::ROLE_USER, false);
        $this->users->method('findById')->with(2)->willReturn($target);
        $this->users->expects(self::once())->method('restore')->with(2);

        $response = $this->controller()->destroy(2, $this->request([]));

        self::assertSame(302, $response->status());
    }

    public function test_missing_user_returns_404(): void
    {
        $this->users->method('findById')->willReturn(null);

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(404);

        $this->controller()->edit(99);
    }

    private function controller(): AdminUserController
    {
        $router = new Router(new Container());
        $router->addController(AdminUserController::class);
        $router->addController(\App\Http\Controllers\ProductsController::class);
        $view = new View(dirname(__DIR__, 3) . '/src/Views', $router, $this->session, new Csrf($this->session));

        return new AdminUserController(
            $this->users,
            $this->hasher,
            new UserPolicy($this->users),
            $view,
            $router,
            $this->session,
            new \App\Core\Validator()
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(array $body): Request
    {
        return (new Request('POST', '/admin/users', body: $body))->withAttribute('user', $this->admin);
    }

    private function user(int $id, string $role, bool $active = true): User
    {
        return new User($id, 'User ' . $id, 'user' . $id . '@test', 'hash-' . $id, $role, $active, '2026-01-01');
    }
}
