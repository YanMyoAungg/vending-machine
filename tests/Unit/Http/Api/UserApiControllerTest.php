<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Api;

use App\Core\Request;
use App\Core\Validator;
use App\Http\Controllers\Api\UserApiController;
use App\Models\PaginatedResult;
use App\Models\User;
use App\Repositories\UserRepositoryInterface;
use App\Services\PasswordHasherInterface;
use App\Services\UserPolicy;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class UserApiControllerTest extends TestCase
{
    private UserRepositoryInterface&MockObject $users;
    private PasswordHasherInterface&MockObject $hasher;
    private User $admin;

    protected function setUp(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->hasher = $this->createMock(PasswordHasherInterface::class);
        $this->admin = new User(1, 'Admin', 'admin@vending.test', 'hash', User::ROLE_ADMIN, true, '2026-01-01');
    }

    public function test_payload_never_leaks_the_password_hash(): void
    {
        $this->users->method('paginate')->willReturn(new PaginatedResult([$this->admin], 1, 1, 20));

        $json = json_decode($this->controller()->index(new Request('GET', '/api/v1/users'))->body(), true);

        self::assertArrayNotHasKey('password_hash', $json['data'][0]);
        self::assertSame('admin@vending.test', $json['data'][0]['email']);
    }

    public function test_store_hashes_and_lowercases_email(): void
    {
        $this->users->method('emailExists')->willReturn(false);
        $this->hasher->expects(self::once())->method('hash')->with('secret123')->willReturn('hashed');
        $this->users->expects(self::once())->method('create')->with(self::callback(
            static fn (array $data): bool => $data['email'] === 'new@example.test' && $data['password_hash'] === 'hashed'
        ))->willReturn($this->admin);

        $response = $this->controller()->store($this->request([
            'name' => 'New User',
            'email' => 'NEW@Example.test',
            'role' => 'user',
            'password' => 'secret123',
        ]));

        self::assertSame(201, $response->status());
    }

    public function test_api_enforces_the_last_admin_guard(): void
    {
        $target = new User(2, 'Other', 'other@test', 'hash', User::ROLE_ADMIN, true, '2026-01-01');
        $this->users->method('findById')->with(2)->willReturn($target);
        $this->users->method('emailExists')->willReturn(false);
        $this->users->method('countActiveAdmins')->willReturn(1);
        $this->users->expects(self::never())->method('update');

        $response = $this->controller()->update(2, $this->request(['is_active' => false]));

        self::assertSame(422, $response->status());
        self::assertSame('validation_failed', json_decode($response->body(), true)['error']['code']);
    }

    public function test_destroy_soft_deletes_and_returns_204(): void
    {
        $target = new User(2, 'User', 'user@test', 'hash', User::ROLE_USER, true, '2026-01-01');
        $this->users->method('findById')->with(2)->willReturn($target);
        $this->users->expects(self::once())->method('softDelete')->with(2);

        $response = $this->controller()->destroy(2, $this->request([]));

        self::assertSame(204, $response->status());
    }

    private function controller(): UserApiController
    {
        return new UserApiController(
            $this->users,
            $this->hasher,
            new UserPolicy($this->users),
            new Validator()
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(array $body): Request
    {
        return (new Request('POST', '/api/v1/users', body: $body, headers: ['Content-Type' => 'application/json']))
            ->withAttribute('user', $this->admin);
    }
}
