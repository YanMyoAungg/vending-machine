<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Core\ArraySession;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Router;
use App\Core\View;
use App\Database\TransactionManagerInterface;
use App\Http\Controllers\ProductsController;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\ProductRepositoryInterface;
use App\Repositories\TransactionRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class ProductsControllerPurchaseApiTest extends TestCase
{
    public function test_purchase_api_returns_201_and_remaining_stock(): void
    {
        $product = $this->product(stock: 5);
        $updated = $this->product(stock: 3);

        $products = $this->createMock(ProductRepositoryInterface::class);
        $products->method('findBySlug')->willReturn($product);
        $products->method('findBySlugForUpdate')->willReturn($product);
        $products->method('findById')->with(1)->willReturn($updated);
        $products->expects(self::once())->method('decrementStock')->with(1, 2);

        $transactions = $this->createMock(TransactionRepositoryInterface::class);
        $transactions->method('create')->willReturn($this->transaction());

        $tx = $this->createMock(TransactionManagerInterface::class);
        $tx->method('transactional')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        $session = new ArraySession();
        $router = new Router(new Container());
        $router->addController(ProductsController::class);
        $view = new View(dirname(__DIR__, 3) . '/src/Views', $router, $session, new Csrf($session));

        $controller = new ProductsController(
            $products,
            $transactions,
            $tx,
            $view,
            $router,
            $session,
            new \App\Core\Validator()
        );

        $request = (new Request('POST', '/api/v1/products/coke/purchase', body: ['quantity' => 2]))
            ->withAttribute('user', new User(1, 'Ada', 'ada@test', 'hash', User::ROLE_USER, true, '2026-01-01'));

        $response = $controller->purchaseApi('coke', $request);
        $payload = json_decode($response->body(), true);

        self::assertSame(201, $response->status());
        self::assertSame(3, $payload['data']['remaining_stock']);
        self::assertSame('20.655', $payload['data']['total_price']);
    }

    public function test_purchase_api_reports_out_of_stock(): void
    {
        $products = $this->createMock(ProductRepositoryInterface::class);
        $products->method('findBySlug')->willReturn($this->product(stock: 1));

        $controller = $this->controller($products);

        $request = (new Request('POST', '/api/v1/products/coke/purchase', body: ['quantity' => 2]))
            ->withAttribute('user', new User(1, 'Ada', 'ada@test', 'hash', User::ROLE_USER, true, '2026-01-01'));

        $response = $controller->purchaseApi('coke', $request);

        self::assertSame(422, $response->status());
        self::assertSame('validation_failed', json_decode($response->body(), true)['error']['code']);
    }

    private function controller(ProductRepositoryInterface $products): ProductsController
    {
        $session = new ArraySession();
        $router = new Router(new Container());
        $router->addController(ProductsController::class);
        $view = new View(dirname(__DIR__, 3) . '/src/Views', $router, $session, new Csrf($session));

        return new ProductsController(
            $products,
            $this->createMock(TransactionRepositoryInterface::class),
            $this->createMock(TransactionManagerInterface::class),
            $view,
            $router,
            $session,
            new \App\Core\Validator()
        );
    }

    private function product(int $stock = 5): Product
    {
        return new Product(
            1,
            'Pepsi',
            'pepsi',
            'Cola',
            '6.885',
            $stock,
            true,
            '2026-01-01 00:00:00',
            '2026-01-01 00:00:00'
        );
    }

    private function transaction(): Transaction
    {
        return new Transaction(
            1,
            1,
            1,
            'Pepsi',
            '6.885',
            3,
            '20.655',
            '2026-01-01 00:00:00',
            'Ada'
        );
    }
}
