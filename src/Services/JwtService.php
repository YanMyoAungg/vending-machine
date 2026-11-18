<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

final class JwtService
{
    public function __construct(
        private readonly string $secret,
        private readonly int $ttlSeconds = 3600,
    ) {
    }

    /**
     * @return array{token: string, token_type: string, expires_in: int, expires_at: string}
     */
    public function issue(User $user): array
    {
        $now = time();
        $expiresAt = $now + $this->ttlSeconds;

        $token = JWT::encode([
            'iss' => 'vending-machine',
            'sub' => (string) $user->id,
            'role' => $user->role,
            'iat' => $now,
            'exp' => $expiresAt,
        ], $this->secret, 'HS256');

        return [
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $this->ttlSeconds,
            'expires_at' => gmdate('c', $expiresAt),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function validate(string $token): ?array
    {
        try {
            $claims = JWT::decode($token, new Key($this->secret, 'HS256'));

            return (array) $claims;
        } catch (Throwable) {
            return null;
        }
    }
}
