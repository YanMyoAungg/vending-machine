<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Core\Container;
use App\Core\Router;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProductsController;
use PHPUnit\Framework\TestCase;

final class RoutesTest extends TestCase
{
    public function test_admin_product_routes_are_protected_and_csrf_guarded(): void
    {
        $routes = $this->router()->routes();
        $store = $this->route($routes, 'admin.products.store');
        $update = $this->route($routes, 'admin.products.update');
        $index = $this->route($routes, 'admin.products.index');

        self::assertSame(['auth', 'role:admin', 'csrf'], $store->middleware);
        self::assertSame(['auth', 'role:admin', 'csrf'], $update->middleware);
        self::assertSame(['auth', 'role:admin'], $index->middleware);
        self::assertSame('POST', $store->method);
        self::assertSame('/admin/products', $store->path);
    }

    public function test_login_routes_are_public_with_csrf_on_post(): void
    {
        $routes = $this->router()->routes();

        self::assertSame([], $this->route($routes, 'login')->middleware);
        self::assertSame(['csrf'], $this->route($routes, 'login.store')->middleware);
        self::assertSame(['auth', 'csrf'], $this->route($routes, 'logout')->middleware);
    }

    public function test_purchase_and_receipt_routes_require_authentication(): void
    {
        $routes = $this->router()->routes();

        self::assertSame(['auth'], $this->route($routes, 'products.purchase')->middleware);
        self::assertSame(['auth', 'csrf'], $this->route($routes, 'products.purchase.store')->middleware);
        self::assertSame(['auth'], $this->route($routes, 'orders.show')->middleware);
        self::assertSame('/orders/{id:\d+}', $this->route($routes, 'orders.show')->path);
    }

    public function test_product_api_routes_use_bearer_and_role_guards(): void
    {
        $routes = $this->router()->routes();

        self::assertSame([], $this->route($routes, 'api.products.index')->middleware);
        self::assertSame([], $this->route($routes, 'api.products.show')->middleware);
        self::assertSame(['api_auth', 'role:admin'], $this->route($routes, 'api.products.store')->middleware);
        self::assertSame(['api_auth', 'role:admin'], $this->route($routes, 'api.products.update')->middleware);
        self::assertSame(['api_auth', 'role:admin'], $this->route($routes, 'api.products.destroy')->middleware);
        self::assertSame(['api_auth'], $this->route($routes, 'api.products.purchase')->middleware);
    }

    public function test_admin_user_routes_are_role_protected(): void
    {
        $routes = $this->router()->routes();

        self::assertSame(['auth', 'role:admin'], $this->route($routes, 'admin.users.index')->middleware);
        self::assertSame(['auth', 'role:admin', 'csrf'], $this->route($routes, 'admin.users.store')->middleware);
        self::assertSame(['auth', 'role:admin', 'csrf'], $this->route($routes, 'admin.users.update')->middleware);
        self::assertSame(['auth', 'role:admin', 'csrf'], $this->route($routes, 'admin.users.destroy')->middleware);
    }

    public function test_user_and_transaction_api_routes_use_bearer_and_role_guards(): void
    {
        $routes = $this->router()->routes();

        self::assertSame(['api_auth', 'role:admin'], $this->route($routes, 'api.users.index')->middleware);
        self::assertSame(['api_auth', 'role:admin'], $this->route($routes, 'api.users.store')->middleware);
        self::assertSame(['api_auth', 'role:admin'], $this->route($routes, 'api.users.update')->middleware);
        self::assertSame(['api_auth', 'role:admin'], $this->route($routes, 'api.users.destroy')->middleware);
        self::assertSame(['api_auth'], $this->route($routes, 'api.transactions.index')->middleware);
    }

    private function router(): Router
    {
        $router = new Router(new Container());
        $router->addController(AuthController::class);
        $router->addController(ProductsController::class);
        $router->addController(\App\Http\Controllers\AdminUserController::class);
        $router->addController(\App\Http\Controllers\TransactionController::class);
        $router->addController(\App\Http\Controllers\Api\ProductApiController::class);
        $router->addController(\App\Http\Controllers\Api\UserApiController::class);
        $router->addController(\App\Http\Controllers\Api\TransactionApiController::class);

        return $router;
    }

    /**
     * @param list<\App\Core\RouteDefinition> $routes
     */
    private function route(array $routes, string $name): \App\Core\RouteDefinition
    {
        foreach ($routes as $route) {
            if ($route->name === $name) {
                return $route;
            }
        }

        self::fail("Route {$name} not found");
    }
}
