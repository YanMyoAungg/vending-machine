<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Core\ArraySession;
use App\Core\Request;
use App\Http\Controllers\Api\AuthApiController;
use App\Models\User;
use App\Repositories\UserRepositoryInterface;
use App\Services\AuthService;
use App\Services\JwtService;
use App\Services\NativePasswordHasher;
use PHPUnit\Framework\TestCase;

final class AuthApiControllerTest extends TestCase
{
    public function test_returns_a_token_for_valid_credentials(): void
    {
        $response = $this->controller()->token(new Request('POST', '/api/v1/auth/token', body: [
            'email' => 'ada@example.test',
            'password' => 'secret',
        ]));

        self::assertSame(200, $response->status());
        $payload = json_decode($response->body(), true);
        self::assertArrayHasKey('token', $payload['data']);
        self::assertSame('Bearer', $payload['data']['token_type']);

        $claims = (new JwtService('test-secret'))->validate($payload['data']['token']);
        self::assertSame(1, (int) $claims['sub']);
    }

    public function test_rejects_bad_credentials_generically(): void
    {
        $response = $this->controller()->token(new Request('POST', '/api/v1/auth/token', body: [
            'email' => 'ada@example.test',
            'password' => 'wrong',
        ]));

        self::assertSame(401, $response->status());
        self::assertSame('invalid_credentials', json_decode($response->body(), true)['error']['code']);
        self::assertStringNotContainsString('wrong', $response->body());
    }

    public function test_rejects_missing_fields_with_details(): void
    {
        $response = $this->controller()->token(new Request('POST', '/api/v1/auth/token', body: []));

        self::assertSame(422, $response->status());
        $payload = json_decode($response->body(), true);
        self::assertSame('validation_failed', $payload['error']['code']);
        self::assertArrayHasKey('email', $payload['error']['details']);
    }

    private function controller(): AuthApiController
    {
        $user = new User(
            1,
            'Ada',
            'ada@example.test',
            password_hash('secret', PASSWORD_DEFAULT),
            User::ROLE_USER,
            true,
            '2026-01-01'
        );

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findByEmail')->willReturn($user);

        $auth = new AuthService($users, new NativePasswordHasher(), new ArraySession());

        return new AuthApiController($auth, new JwtService('test-secret'), new \App\Core\Validator());
    }
}
