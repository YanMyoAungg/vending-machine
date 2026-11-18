<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Router;
use App\Tests\Fixtures\FixtureController;
use App\Tests\Fixtures\RecordingMiddleware;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function test_matches_attribute_route_and_extracts_parameter(): void
    {
        $response = $this->routerWithFixture()->dispatch(new Request('GET', '/greet/ada'));

        self::assertSame('Hello ada', $response->body());
        self::assertSame(200, $response->status());
    }

    public function test_returns_405_with_allow_header(): void
    {
        try {
            $this->routerWithFixture()->dispatch(new Request('POST', '/greet/ada'));
            self::fail('Expected a 405');
        } catch (HttpException $exception) {
            self::assertSame(405, $exception->status);
            self::assertSame('GET', $exception->headers['Allow']);
        }
    }

    public function test_returns_404_for_unknown_path(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(404);

        $this->routerWithFixture()->dispatch(new Request('GET', '/nope'));
    }

    public function test_middleware_runs_globally_then_in_declared_order(): void
    {
        RecordingMiddleware::$calls = [];
        $router = $this->routerWithFixture();
        $router->useGlobal(new RecordingMiddleware('global'));

        $router->dispatch(new Request('GET', '/guarded'));

        self::assertSame(['global', 'first', 'second'], RecordingMiddleware::$calls);
    }

    public function test_url_generation_uses_route_parameters_and_query(): void
    {
        $url = $this->routerWithFixture()->url('fixture.greet', ['name' => 'ada', 'x' => 1]);

        self::assertSame('/greet/ada?x=1', $url);
    }

    public function test_numeric_route_constraints_are_enforced(): void
    {
        $router = $this->routerWithFixture();

        self::assertSame('Item 42', $router->dispatch(new Request('GET', '/item/42'))->body());

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(404);

        $router->dispatch(new Request('GET', '/item/abc'));
    }

    public function test_routes_expose_their_middleware_for_inspection(): void
    {
        $routes = $this->routerWithFixture()->routes();
        $guarded = array_values(array_filter($routes, fn ($route) => $route->name === 'fixture.guarded'))[0];

        self::assertSame(['first', 'second'], $guarded->middleware);
        self::assertSame(FixtureController::class, $guarded->controller);
        self::assertSame('guarded', $guarded->action);
    }

    private function routerWithFixture(): Router
    {
        $router = new Router(new Container());
        $router->middleware('first', fn () => new RecordingMiddleware('first'));
        $router->middleware('second', fn () => new RecordingMiddleware('second'));
        $router->addController(FixtureController::class);

        return $router;
    }
}
