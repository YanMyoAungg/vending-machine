<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Models\User;
use App\Services\JwtService;
use PHPUnit\Framework\TestCase;

final class JwtServiceTest extends TestCase
{
    public function test_issues_and_validates_token_with_user_claims(): void
    {
        $jwt = new JwtService('test-secret');

        $issued = $jwt->issue($this->user(id: 7, role: User::ROLE_ADMIN));
        $claims = $jwt->validate($issued['token']);

        self::assertSame(7, (int) $claims['sub']);
        self::assertSame('admin', $claims['role']);
        self::assertSame(3600, $issued['expires_in']);
        self::assertSame('Bearer', $issued['token_type']);
    }

    public function test_rejects_tampered_token(): void
    {
        $jwt = new JwtService('test-secret');
        $token = $jwt->issue($this->user())['token'];

        self::assertNull($jwt->validate($token . 'x'));
    }

    public function test_rejects_token_signed_with_another_secret(): void
    {
        $token = (new JwtService('other-secret'))->issue($this->user())['token'];

        self::assertNull((new JwtService('test-secret'))->validate($token));
    }

    public function test_rejects_expired_token(): void
    {
        $expired = new JwtService('test-secret', -10);
        $token = $expired->issue($this->user())['token'];

        self::assertNull($expired->validate($token));
    }

    private function user(int $id = 1, string $role = User::ROLE_USER): User
    {
        return new User($id, 'Ada', 'ada@example.test', 'hash', $role, true, '2026-01-01');
    }
}
