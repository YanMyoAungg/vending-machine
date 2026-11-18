<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;

final class VerifyCsrf implements MiddlewareInterface
{
    public function __construct(private readonly Csrf $csrf)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $token = $request->input('_token') ?? $request->header('X-CSRF-Token');

        if (!$this->csrf->check(is_string($token) ? $token : null)) {
            throw new HttpException(419, 'Your session expired. Please try again.');
        }

        return $next($request);
    }
}
