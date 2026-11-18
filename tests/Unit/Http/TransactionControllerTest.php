<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Core\ArraySession;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Router;
use App\Core\View;
use App\Http\Controllers\TransactionController;
use App\Models\PaginatedResult;
use App\Models\Transaction;
use App\Repositories\TransactionRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class TransactionControllerTest extends TestCase
{
    private TransactionRepositoryInterface&MockObject $transactions;
    private ArraySession $session;

    protected function setUp(): void
    {
        $this->transactions = $this->createMock(TransactionRepositoryInterface::class);
        $this->session = new ArraySession();
    }

    public function test_index_paginates_transactions_newest_first(): void
    {
        $this->transactions->expects(self::once())->method('paginate')->with(2, 20, null)
            ->willReturn(new PaginatedResult([$this->transaction()], 21, 2, 20));

        $body = $this->controller()->index(new Request('GET', '/admin/transactions', ['page' => '2']))->body();

        self::assertStringContainsString('Ada Lovelace', $body);
        self::assertStringContainsString('USD 7.98', $body);
        self::assertStringContainsString('Pepsi', $body);
        self::assertStringContainsString('Page 2 of 2', $body);
    }

    public function test_index_renders_an_empty_state(): void
    {
        $this->transactions->method('paginate')->willReturn(new PaginatedResult([], 0, 1, 20));

        $body = $this->controller()->index(new Request('GET', '/admin/transactions'))->body();

        self::assertStringContainsString('No purchases yet', $body);
    }

    private function controller(): TransactionController
    {
        $router = new Router(new Container());
        $router->addController(TransactionController::class);
        $router->addController(\App\Http\Controllers\ProductsController::class);
        $view = new View(dirname(__DIR__, 3) . '/src/Views', $router, $this->session, new Csrf($this->session));

        return new TransactionController($this->transactions, $view);
    }

    private function transaction(): Transaction
    {
        return new Transaction(
            1,
            1,
            1,
            'Pepsi',
            '3.990',
            2,
            '7.980',
            '2026-01-01 10:00:00',
            'Ada Lovelace'
        );
    }
}
