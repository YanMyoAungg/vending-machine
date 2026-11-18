<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\HttpException;
use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\UserRepositoryInterface;
use App\Services\JwtService;

final class ApiAuth implements MiddlewareInterface
{
    public function __construct(
        private readonly JwtService $jwt,
        private readonly UserRepositoryInterface $users,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $header = $request->header('authorization');

        if ($header === null || preg_match('/^Bearer\s+(.+)$/i', $header, $matches) !== 1) {
            throw new HttpException(401, 'Authentication required.');
        }

        $claims = $this->jwt->validate($matches[1]);

        if ($claims === null || !isset($claims['sub'])) {
            throw new HttpException(401, 'Invalid or expired token.');
        }

        $user = $this->users->findById((int) $claims['sub']);

        if ($user === null || !$user->isActive) {
            throw new HttpException(401, 'Invalid or expired token.');
        }

        return $next($request->withAttribute('user', $user));
    }
}
