<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;

final class SessionAuth implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        if ($request->attribute('user') === null) {
            $target = '/login';

            if ($request->path !== '/' && $request->path !== '/login') {
                $next = $request->path;

                if ($request->query !== []) {
                    $next .= '?' . http_build_query($request->query);
                }

                $target .= '?next=' . rawurlencode($next);
            }

            return Response::redirect($target);
        }

        return $next($request);
    }
}
