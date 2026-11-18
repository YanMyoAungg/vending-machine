<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\AuthService;

final class ResolveUser implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly View $view,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (str_starts_with($request->path, '/api/')) {
            return $next($request);
        }

        $user = $this->auth->user();

        $this->view->share('authUser', $user);

        if ($user !== null) {
            $request = $request->withAttribute('user', $user);
        }

        return $next($request);
    }
}
