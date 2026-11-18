<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Core\ArraySession;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Router;
use App\Core\View;
use App\Database\TransactionManagerInterface;
use App\Http\Controllers\ProductsController;
use App\Models\PaginatedResult;
use App\Models\Product;
use App\Repositories\ProductRepositoryInterface;
use App\Repositories\TransactionRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ProductsControllerStorefrontTest extends TestCase
{
    private ProductRepositoryInterface&MockObject $products;
    private TransactionRepositoryInterface&MockObject $transactions;
    private TransactionManagerInterface&MockObject $tx;
    private ArraySession $session;

    protected function setUp(): void
    {
        $this->products = $this->createMock(ProductRepositoryInterface::class);
        $this->transactions = $this->createMock(TransactionRepositoryInterface::class);
        $this->tx = $this->createMock(TransactionManagerInterface::class);
        $this->session = new ArraySession();
    }

    public function test_index_passes_clamped_list_options_and_renders_empty_state(): void
    {
        $this->products->expects(self::once())->method('paginate')->with(self::callback(
            static fn (array $options): bool => $options['page'] === 1
                && $options['per_page'] === 9
                && $options['sort'] === 'name'
                && $options['direction'] === 'asc'
        ))->willReturn(new PaginatedResult([], 0, 1, 9));

        $response = $this->controller()->index(new Request('GET', '/products', ['page' => '0', 'sort' => 'nope']));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('No products are available', $response->body());
    }

    public function test_index_renders_products_with_prices_and_links(): void
    {
        $this->products->method('paginate')->willReturn(new PaginatedResult([$this->product()], 1, 1, 9));

        $body = $this->controller()->index(new Request('GET', '/products'))->body();

        self::assertStringContainsString('Coke', $body);
        self::assertStringContainsString('USD 3.99', $body);
        self::assertStringContainsString('/products/coke', $body);
        self::assertStringContainsString('Sort by price', $body);
    }

    public function test_page_beyond_the_last_shows_a_reset_link(): void
    {
        $this->products->method('paginate')->willReturn(new PaginatedResult([], 3, 99, 2));

        $body = $this->controller()->index(new Request('GET', '/products', ['page' => '99']))->body();

        self::assertStringContainsString('No products on this page', $body);
        self::assertStringContainsString('Back to the first page', $body);
    }

    public function test_show_renders_product_details(): void
    {
        $this->products->method('findBySlug')->with('coke')->willReturn($this->product());

        $body = $this->controller()->show('coke')->body();

        self::assertStringContainsString('Coke', $body);
        self::assertStringContainsString('USD 3.99', $body);
        self::assertStringContainsString('12 in stock', $body);
        self::assertStringContainsString('/products/coke/purchase', $body);
    }

    public function test_show_returns_404_for_inactive_product(): void
    {
        $this->products->method('findBySlug')->with('coke')->willReturn(null);

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(404);

        $this->controller()->show('coke');
    }

    private function controller(): ProductsController
    {
        $router = new Router(new Container());
        $router->addController(ProductsController::class);
        $view = new View(dirname(__DIR__, 3) . '/src/Views', $router, $this->session, new Csrf($this->session));

        return new ProductsController(
            $this->products,
            $this->transactions,
            $this->tx,
            $view,
            $router,
            $this->session,
            new \App\Core\Validator()
        );
    }

    private function product(): Product
    {
        return new Product(
            1,
            'Coke',
            'coke',
            'Classic cola, chilled.',
            '3.990',
            12,
            true,
            '2026-01-01 00:00:00',
            '2026-01-01 00:00:00'
        );
    }
}
