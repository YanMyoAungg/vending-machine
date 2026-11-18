<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use App\Core\Application;
use App\Core\ArraySession;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Router;
use App\Core\View;
use App\Tests\Fixtures\FixtureController;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    public function test_renders_an_html_404_page(): void
    {
        $response = $this->application()->handle(new Request('GET', '/nope'));

        self::assertSame(404, $response->status());
        self::assertStringContainsString('Not found', $response->body());
    }

    public function test_renders_json_for_api_requests(): void
    {
        $response = $this->application()->handle(new Request('GET', '/api/nope'));

        self::assertSame(404, $response->status());
        $payload = json_decode($response->body(), true);
        self::assertSame('not_found', $payload['error']['code']);
    }

    public function test_converts_validation_exceptions_to_422_json(): void
    {
        $response = $this->application()->handle(new Request('GET', '/invalid', headers: ['Accept' => 'application/json']));

        self::assertSame(422, $response->status());
        $payload = json_decode($response->body(), true);
        self::assertSame('validation_failed', $payload['error']['code']);
        self::assertSame(['Too many.'], $payload['error']['details']['quantity']);
    }

    public function test_hides_unexpected_exception_details_on_500(): void
    {
        $response = $this->application()->handle(new Request('GET', '/boom'));

        self::assertSame(500, $response->status());
        self::assertStringNotContainsString('secret detail', $response->body());
    }

    public function test_passes_http_exception_headers_to_the_response(): void
    {
        $response = $this->application()->handle(new Request('GET', '/api/v1/greet/ada'));

        self::assertSame(404, $response->status());
        self::assertSame('application/json; charset=utf-8', $response->headers()['Content-Type']);
    }

    private function application(): Application
    {
        $container = new Container();
        $router = new Router($container);
        $router->addController(FixtureController::class);
        $session = new ArraySession();
        $csrf = new Csrf($session);
        $view = new View(dirname(__DIR__, 3) . '/src/Views', $router, $session, $csrf);

        return new Application($router, $view);
    }
}
