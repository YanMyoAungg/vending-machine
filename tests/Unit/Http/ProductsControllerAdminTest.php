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

final class ProductsControllerAdminTest extends TestCase
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

    public function test_store_rejects_invalid_input_and_preserves_old_values(): void
    {
        $this->products->expects(self::never())->method('create');

        $response = $this->controller()->store(new Request('POST', '/admin/products', body: [
            'name' => '',
            'price' => '-1',
            'quantity_available' => '-2',
        ]));

        self::assertSame(422, $response->status());
        self::assertStringContainsString('Name is required.', $response->body());
        self::assertStringContainsString('value="-1"', $response->body());
    }

    public function test_store_rejects_a_duplicate_slug(): void
    {
        $this->products->method('slugExists')->with('coke')->willReturn(true);
        $this->products->expects(self::never())->method('create');

        $response = $this->controller()->store($this->validProductRequest());

        self::assertSame(422, $response->status());
        self::assertStringContainsString('already in use', $response->body());
    }

    public function test_store_derives_slug_and_redirects_with_flash(): void
    {
        $this->products->method('slugExists')->with('diet-coke')->willReturn(false);
        $this->products->expects(self::once())->method('create')->with(self::callback(
            static fn (array $data): bool => $data['slug'] === 'diet-coke'
                && $data['price'] === '2.500'
                && $data['quantity_available'] === 4
        ))->willReturn($this->product());

        $response = $this->controller()->store(new Request('POST', '/admin/products', body: [
            'name' => 'Diet Coke',
            'price' => '2.500',
            'quantity_available' => '4',
            'description' => 'Lighter cola.',
            'is_active' => '1',
        ]));

        self::assertSame(302, $response->status());
        self::assertSame('/admin/products', $response->headers()['Location']);
        self::assertSame('Product created.', $this->session->pullFlash('success'));
    }

    public function test_admin_index_includes_inactive_products(): void
    {
        $this->products->expects(self::once())->method('paginate')->with(self::callback(
            static fn (array $options): bool => ($options['include_inactive'] ?? false) === true
        ))->willReturn(new PaginatedResult([
            $this->product(isActive: false),
        ], 1, 1, 20));

        $body = $this->controller()->adminIndex(new Request('GET', '/admin/products'))->body();

        self::assertStringContainsString('Inactive', $body);
        self::assertStringContainsString('Coke', $body);
    }

    public function test_update_uses_slug_uniqueness_ignoring_the_product(): void
    {
        $product = $this->product();
        $this->products->method('findById')->with($product->id)->willReturn($product);
        $this->products->expects(self::once())->method('slugExists')->with('coke', $product->id)->willReturn(false);
        $this->products->expects(self::once())->method('update')->with($product->id)->willReturn($product);

        $response = $this->controller()->update($product->id, new Request('POST', '/admin/products/1', body: [
            'name' => 'Coke',
            'slug' => 'coke',
            'price' => '3.990',
            'quantity_available' => '12',
            'is_active' => '1',
        ]));

        self::assertSame(302, $response->status());
        self::assertSame('/admin/products', $response->headers()['Location']);
    }

    public function test_destroy_soft_deletes_the_product(): void
    {
        $product = $this->product();
        $this->products->method('findById')->willReturn($product);
        $this->products->expects(self::once())->method('softDelete')->with($product->id);

        $response = $this->controller()->destroy($product->id);

        self::assertSame(302, $response->status());
        self::assertSame('Product removed from the store.', $this->session->pullFlash('success'));
    }

    public function test_edit_returns_404_for_a_missing_product(): void
    {
        $this->products->method('findById')->willReturn(null);

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(404);

        $this->controller()->edit(999);
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

    private function validProductRequest(): Request
    {
        return new Request('POST', '/admin/products', body: [
            'name' => 'Coke',
            'price' => '3.990',
            'quantity_available' => '12',
            'is_active' => '1',
        ]);
    }

    private function product(bool $isActive = true): Product
    {
        return new Product(
            1,
            'Coke',
            'coke',
            'Cola',
            '3.990',
            12,
            $isActive,
            '2026-01-01 00:00:00',
            '2026-01-01 00:00:00'
        );
    }
}
