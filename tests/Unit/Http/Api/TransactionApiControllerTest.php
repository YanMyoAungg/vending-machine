<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Api;

use App\Core\Request;
use App\Http\Controllers\Api\TransactionApiController;
use App\Models\PaginatedResult;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\TransactionRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class TransactionApiControllerTest extends TestCase
{
    private TransactionRepositoryInterface&MockObject $transactions;

    protected function setUp(): void
    {
        $this->transactions = $this->createMock(TransactionRepositoryInterface::class);
    }

    public function test_scope_me_filters_to_the_token_user(): void
    {
        $this->transactions->expects(self::once())->method('paginate')->with(1, 20, 5)
            ->willReturn(new PaginatedResult([], 0, 1, 20));

        $this->controller()->index(new Request(
            'GET',
            '/api/v1/transactions',
            ['scope' => 'me'],
            attributes: ['user' => $this->user(5, User::ROLE_ADMIN)]
        ));
    }

    public function test_admin_without_scope_sees_all_transactions(): void
    {
        $this->transactions->expects(self::once())->method('paginate')->with(1, 20, null)
            ->willReturn(new PaginatedResult([$this->transaction()], 1, 1, 20));

        $response = $this->controller()->index(new Request(
            'GET',
            '/api/v1/transactions',
            attributes: ['user' => $this->user(5, User::ROLE_ADMIN)]
        ));

        $json = json_decode($response->body(), true);
        self::assertSame(1, $json['meta']['total']);
        self::assertSame('Ada Lovelace', $json['data'][0]['buyer_name']);
    }

    public function test_regular_user_is_always_limited_to_own_transactions(): void
    {
        $this->transactions->expects(self::once())->method('paginate')->with(1, 20, 7)
            ->willReturn(new PaginatedResult([], 0, 1, 20));

        $this->controller()->index(new Request(
            'GET',
            '/api/v1/transactions',
            attributes: ['user' => $this->user(7, User::ROLE_USER)]
        ));
    }

    private function controller(): TransactionApiController
    {
        return new TransactionApiController($this->transactions);
    }

    private function user(int $id, string $role): User
    {
        return new User($id, 'User ' . $id, 'user' . $id . '@test', 'hash', $role, true, '2026-01-01');
    }

    private function transaction(): Transaction
    {
        return new Transaction(1, 1, 1, 'Pepsi', '6.885', 2, '13.770', '2026-01-01', 'Ada Lovelace');
    }
}
