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
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\ProductRepositoryInterface;
use App\Repositories\TransactionRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProductsControllerPurchaseTest extends TestCase
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
        $this->tx->method('transactional')->willReturnCallback(
            static fn (callable $callback): mixed => $callback()
        );
        $this->session = new ArraySession();
    }

    public function test_purchase_decrements_stock_and_logs_snapshot(): void
    {
        $product = $this->product(price: '6.885', stock: 8);
        $this->products->method('findBySlug')->willReturn($product);
        $this->products->method('findBySlugForUpdate')->willReturn($product);

        $this->products->expects(self::once())->method('decrementStock')->with($product->id, 3);

        $this->transactions->expects(self::once())->method('create')->with(self::callback(
            static fn (array $data): bool => $data['product_name'] === 'Pepsi'
                && $data['unit_price'] === '6.885'
                && $data['total_price'] === '20.655'
                && $data['quantity'] === 3
                && $data['user_id'] === 1
        ))->willReturn($this->transaction());

        $response = $this->controller()->purchase('pepsi', $this->purchaseRequest(3));

        self::assertSame(302, $response->status());
        self::assertSame('/orders/1', $response->headers()['Location']);
        self::assertSame('Purchase complete.', $this->session->pullFlash('success'));
    }

    public function test_purchase_at_exact_stock_boundary_succeeds(): void
    {
        $product = $this->product(stock: 2);
        $this->products->method('findBySlug')->willReturn($product);
        $this->products->method('findBySlugForUpdate')->willReturn($product);

        $this->products->expects(self::once())->method('decrementStock')->with($product->id, 2);
        $this->transactions->method('create')->willReturn($this->transaction());

        $this->controller()->purchase('coke', $this->purchaseRequest(2));

        self::assertTrue(true);
    }

    public function test_purchase_above_stock_does_not_write(): void
    {
        $product = $this->product(stock: 1);
        $this->products->method('findBySlug')->willReturn($product);

        $this->products->expects(self::never())->method('decrementStock');
        $this->transactions->expects(self::never())->method('create');

        $response = $this->controller()->purchase('coke', $this->purchaseRequest(2));

        self::assertSame(422, $response->status());
        self::assertStringContainsString('Only 1 left', $response->body());
    }

    public function test_invalid_quantity_rerenders_the_form(): void
    {
        $this->products->method('findBySlug')->willReturn($this->product());

        $response = $this->controller()->purchase('coke', $this->purchaseRequest(0));

        self::assertSame(422, $response->status());
        self::assertStringContainsString('Quantity must be at least 1.', $response->body());
    }

    public function test_transaction_exceptions_are_not_swallowed(): void
    {
        $product = $this->product();
        $this->products->method('findBySlug')->willReturn($product);
        $this->products->method('findBySlugForUpdate')->willReturn($product);
        $this->tx->method('transactional')->willThrowException(new RuntimeException('DB down'));

        $this->expectException(RuntimeException::class);

        $this->controller()->purchase('coke', $this->purchaseRequest(1));
    }

    public function test_locked_stock_recheck_prevents_overselling(): void
    {
        $display = $this->product(stock: 5);
        $locked = $this->product(stock: 0);

        $this->products->method('findBySlug')->willReturn($display);
        $this->products->method('findBySlugForUpdate')->willReturn($locked);
        $this->products->expects(self::never())->method('decrementStock');
        $this->transactions->expects(self::never())->method('create');

        $response = $this->controller()->purchase('pepsi', $this->purchaseRequest(2));

        self::assertSame(422, $response->status());
        self::assertStringContainsString('Only 0 left', $response->body());
    }

    public function test_purchase_form_shows_product_details(): void
    {
        $this->products->method('findBySlug')->with('pepsi')->willReturn($this->product(name: 'Pepsi', price: '6.885'));

        $body = $this->controller()->purchaseForm('pepsi')->body();

        self::assertStringContainsString('Pepsi', $body);
        self::assertStringContainsString('USD 6.885', $body);
        self::assertStringContainsString('name="quantity"', $body);
    }

    public function test_receipt_is_visible_to_owner_and_admin_only(): void
    {
        $transaction = $this->transaction(userId: 1);
        $this->transactions->method('findById')->with($transaction->id)->willReturn($transaction);

        $owner = $this->controller()->receipt($transaction->id, $this->requestAs($this->user(1)));
        self::assertSame(200, $owner->status());
        self::assertStringContainsString('Pepsi', $owner->body());

        $admin = $this->controller()->receipt($transaction->id, $this->requestAs($this->user(2, User::ROLE_ADMIN)));
        self::assertSame(200, $admin->status());

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(403);
        $this->controller()->receipt($transaction->id, $this->requestAs($this->user(3)));
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

    private function purchaseRequest(int $quantity): Request
    {
        return (new Request('POST', '/products/coke/purchase', body: ['quantity' => (string) $quantity]))
            ->withAttribute('user', $this->user(1));
    }

    private function requestAs(User $user): Request
    {
        return (new Request('GET', '/orders/1'))->withAttribute('user', $user);
    }

    private function user(int $id, string $role = User::ROLE_USER): User
    {
        return new User($id, 'User ' . $id, 'user' . $id . '@test', 'hash', $role, true, '2026-01-01');
    }

    private function product(string $name = 'Pepsi', string $price = '6.885', int $stock = 8): Product
    {
        return new Product(
            1,
            $name,
            strtolower($name),
            'Cola',
            $price,
            $stock,
            true,
            '2026-01-01 00:00:00',
            '2026-01-01 00:00:00'
        );
    }

    private function transaction(int $userId = 1): Transaction
    {
        return new Transaction(
            1,
            $userId,
            1,
            'Pepsi',
            '6.885',
            3,
            '20.655',
            '2026-01-01 00:00:00',
            'User ' . $userId
        );
    }
}
