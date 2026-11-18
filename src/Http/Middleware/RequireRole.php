<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\HttpException;
use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;
use App\Models\User;

final class RequireRole implements MiddlewareInterface
{
    public function __construct(private readonly string $role)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $user = $request->attribute('user');

        if (!$user instanceof User) {
            throw new HttpException(401, 'Authentication required.');
        }

        if ($user->role !== $this->role) {
            throw new HttpException(403, 'You are not allowed to do that.');
        }

        return $next($request);
    }
}
