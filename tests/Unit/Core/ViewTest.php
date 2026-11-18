<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use App\Core\ArraySession;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;
use PHPUnit\Framework\TestCase;

final class ViewTest extends TestCase
{
    public function test_renders_layout_and_escapes_output(): void
    {
        $response = $this->view()->render('hello', ['name' => '<b>Ada</b>']);

        self::assertStringContainsString('&lt;b&gt;Ada&lt;/b&gt;', $response->body());
        self::assertStringContainsString('Layout', $response->body());
        self::assertSame('text/html; charset=utf-8', $response->headers()['Content-Type']);
    }

    public function test_shared_data_is_available_to_templates(): void
    {
        $view = $this->view();
        $view->share('brand', 'Vending');

        self::assertStringContainsString('Vending', $view->render('hello', ['name' => 'Ada'])->body());
    }

    private function view(): View
    {
        $session = new ArraySession();

        return new View(
            __DIR__ . '/../../Fixtures/views',
            new Router(new Container()),
            $session,
            new Csrf($session)
        );
    }
}
