<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Http\Middleware\ApiAuth;
use App\Models\User;
use App\Repositories\UserRepositoryInterface;
use App\Services\JwtService;
use PHPUnit\Framework\TestCase;

final class ApiAuthTest extends TestCase
{
    public function test_rejects_missing_bearer_token(): void
    {
        $middleware = new ApiAuth(new JwtService('test-secret'), $this->createMock(UserRepositoryInterface::class));

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(401);

        $middleware->handle(new Request('GET', '/api/v1/products'), fn (): Response => Response::html('never'));
    }

    public function test_rejects_invalid_token(): void
    {
        $middleware = new ApiAuth(new JwtService('test-secret'), $this->createMock(UserRepositoryInterface::class));

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(401);

        $middleware->handle(
            new Request('GET', '/api/v1/products', headers: ['Authorization' => 'Bearer nope']),
            fn (): Response => Response::html('never')
        );
    }

    public function test_rejects_token_for_inactive_user(): void
    {
        $jwt = new JwtService('test-secret');
        $user = new User(5, 'Ada', 'ada@example.test', 'hash', User::ROLE_USER, false, '2026-01-01');
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->with(5)->willReturn($user);
        $middleware = new ApiAuth($jwt, $users);

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(401);

        $middleware->handle(
            new Request('GET', '/api/v1/products', headers: [
                'Authorization' => 'Bearer ' . $jwt->issue($user)['token'],
            ]),
            fn (): Response => Response::html('never')
        );
    }

    public function test_sets_the_user_attribute_for_a_valid_token(): void
    {
        $jwt = new JwtService('test-secret');
        $user = new User(5, 'Ada', 'ada@example.test', 'hash', User::ROLE_USER, true, '2026-01-01');
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->with(5)->willReturn($user);
        $middleware = new ApiAuth($jwt, $users);

        $captured = null;
        $response = $middleware->handle(
            new Request('GET', '/api/v1/transactions', headers: [
                'Authorization' => 'Bearer ' . $jwt->issue($user)['token'],
            ]),
            function (Request $request) use (&$captured): Response {
                $captured = $request->attribute('user');

                return Response::json(['ok' => true]);
            }
        );

        self::assertSame(200, $response->status());
        self::assertSame($user->id, $captured?->id);
    }
}
