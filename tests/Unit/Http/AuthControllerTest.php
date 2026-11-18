<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Core\ArraySession;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;
use App\Http\Controllers\AuthController;
use App\Models\User;
use App\Repositories\UserRepositoryInterface;
use App\Services\AuthService;
use App\Services\NativePasswordHasher;
use PHPUnit\Framework\TestCase;

final class AuthControllerTest extends TestCase
{
    public function test_login_form_renders(): void
    {
        $response = $this->controller()->showLogin(new \App\Core\Request('GET', '/login'));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Sign in', $response->body());
        self::assertStringContainsString('name="_token"', $response->body());
    }

    public function test_missing_credentials_are_rejected(): void
    {
        $response = $this->controller()->login(new \App\Core\Request('POST', '/login', body: []));

        self::assertSame(422, $response->status());
        self::assertStringContainsString('Email is required.', $response->body());
    }

    public function test_bad_credentials_use_a_generic_message(): void
    {
        $response = $this->controller()->login(new \App\Core\Request('POST', '/login', body: [
            'email' => 'ada@example.test',
            'password' => 'wrong',
        ]));

        self::assertSame(422, $response->status());
        self::assertStringContainsString('Invalid email or password.', $response->body());
        self::assertStringNotContainsString('wrong', $response->body());
    }

    public function test_successful_login_redirects_to_products(): void
    {
        $response = $this->controller()->login(new \App\Core\Request('POST', '/login', body: [
            'email' => 'ada@example.test',
            'password' => 'secret',
            'next' => 'https://evil.test',
        ]));

        self::assertSame(302, $response->status());
        self::assertSame('/products', $response->headers()['Location']);
    }

    public function test_backslash_in_next_cannot_escape_the_site(): void
    {
        foreach (['/\\evil.test', "/\nevil.test"] as $next) {
            $response = $this->controller()->login(new \App\Core\Request('POST', '/login', body: [
                'email' => 'ada@example.test',
                'password' => 'secret',
                'next' => $next,
            ]));

            self::assertSame(302, $response->status());
            self::assertSame('/products', $response->headers()['Location']);
        }
    }

    public function test_logout_destroys_the_session_and_redirects(): void
    {
        $session = new ArraySession();
        $session->set('auth_user_id', 1);

        $response = $this->controller($session)->logout();

        self::assertSame(302, $response->status());
        self::assertNull($session->get('auth_user_id'));
    }

    private function controller(?ArraySession $session = null): AuthController
    {
        $session ??= new ArraySession();
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findByEmail')->willReturnCallback(
            static fn (string $email): ?User => $email === 'ada@example.test'
                ? new User(1, 'Ada', 'ada@example.test', password_hash('secret', PASSWORD_DEFAULT), User::ROLE_USER, true, '2026-01-01')
                : null
        );

        $auth = new AuthService($users, new NativePasswordHasher(), $session);
        $router = new Router(new Container());
        $router->addController(AuthController::class);
        $view = new View(dirname(__DIR__, 3) . '/src/Views', $router, $session, new Csrf($session));

        return new AuthController($auth, $view, $router, new \App\Core\Validator());
    }
}
