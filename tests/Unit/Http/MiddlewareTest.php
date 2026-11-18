<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Core\ArraySession;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\ResolveUser;
use App\Http\Middleware\SessionAuth;
use App\Http\Middleware\VerifyCsrf;
use App\Models\User;
use App\Repositories\UserRepositoryInterface;
use App\Services\AuthService;
use App\Services\NativePasswordHasher;
use PHPUnit\Framework\TestCase;

final class MiddlewareTest extends TestCase
{
    public function test_resolve_user_sets_the_request_attribute_and_view_share(): void
    {
        $user = $this->user(User::ROLE_ADMIN);
        $session = new ArraySession();
        $session->set('auth_user_id', $user->id);
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->with($user->id)->willReturn($user);
        $auth = new AuthService($users, new NativePasswordHasher(), $session);
        $view = $this->view($session);

        $captured = null;
        $response = (new ResolveUser($auth, $view))->handle(
            new Request('GET', '/admin'),
            function (Request $request) use (&$captured): Response {
                $captured = $request->attribute('user');

                return Response::html('ok');
            }
        );

        self::assertSame('ok', $response->body());
        self::assertSame($user->id, $captured?->id);

        $view->render('auth/login', ['next' => '', 'errors' => [], 'old' => []]);
        self::assertTrue($view->shared('authUser') instanceof User);
    }

    public function test_resolve_user_skips_api_requests(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::never())->method('findById');
        $session = new ArraySession();
        $session->set('auth_user_id', 1);
        $auth = new AuthService($users, new NativePasswordHasher(), $session);

        $captured = 'not-called';
        (new ResolveUser($auth, $this->view($session)))->handle(
            new Request('GET', '/api/v1/products'),
            function (Request $request) use (&$captured): Response {
                $captured = $request->attribute('user');

                return Response::json(['ok' => true]);
            }
        );

        self::assertNull($captured);
    }

    public function test_session_auth_redirects_guests_with_next_path(): void
    {
        $response = (new SessionAuth())->handle(
            new Request('GET', '/admin/products'),
            fn (): Response => Response::html('never')
        );

        self::assertSame(302, $response->status());
        self::assertSame('/login?next=%2Fadmin%2Fproducts', $response->headers()['Location']);
    }

    public function test_session_auth_preserves_query_strings(): void
    {
        $response = (new SessionAuth())->handle(
            new Request('GET', '/products', ['search' => 'pepsi', 'page' => '2']),
            fn (): Response => Response::html('never')
        );

        self::assertSame(
            '/login?next=' . rawurlencode('/products?search=pepsi&page=2'),
            $response->headers()['Location']
        );
    }

    public function test_session_auth_passes_authenticated_requests(): void
    {
        $request = (new Request('GET', '/admin'))->withAttribute('user', $this->user(User::ROLE_ADMIN));

        $response = (new SessionAuth())->handle($request, fn (): Response => Response::html('ok'));

        self::assertSame('ok', $response->body());
    }

    public function test_require_role_rejects_the_wrong_role(): void
    {
        $request = (new Request('GET', '/admin'))->withAttribute('user', $this->user(User::ROLE_USER));

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(403);

        (new RequireRole(User::ROLE_ADMIN))->handle($request, fn (): Response => Response::html('never'));
    }

    public function test_verify_csrf_rejects_post_without_token(): void
    {
        $session = new ArraySession();
        $middleware = new VerifyCsrf(new Csrf($session));

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(419);

        $middleware->handle(new Request('POST', '/login'), fn (): Response => Response::html('never'));
    }

    public function test_verify_csrf_accepts_a_valid_token(): void
    {
        $session = new ArraySession();
        $csrf = new Csrf($session);
        $middleware = new VerifyCsrf($csrf);

        $response = $middleware->handle(
            new Request('POST', '/login', body: ['_token' => $csrf->token()]),
            fn (): Response => Response::html('ok')
        );

        self::assertSame('ok', $response->body());
    }

    private function view(ArraySession $session): View
    {
        return new View(
            dirname(__DIR__, 3) . '/src/Views',
            (function (): Router {
                $router = new Router(new Container());
                $router->addController(\App\Http\Controllers\AuthController::class);
                $router->addController(\App\Http\Controllers\ProductsController::class);

                return $router;
            })(),
            $session,
            new Csrf($session)
        );
    }

    private function user(string $role): User
    {
        return new User(1, 'Ada', 'ada@test', 'hash', $role, true, '2026-01-01 00:00:00');
    }
}
