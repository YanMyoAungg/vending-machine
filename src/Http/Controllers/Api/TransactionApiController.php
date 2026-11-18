<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Route;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\TransactionRepositoryInterface;

final class TransactionApiController
{
    public function __construct(private readonly TransactionRepositoryInterface $transactions)
    {
    }

    #[Route('GET', '/api/v1/transactions', name: 'api.transactions.index')]
    #[Middleware('api_auth')]
    public function index(Request $request): Response
    {
        $user = $request->attribute('user');

        if (!$user instanceof User) {
            throw new HttpException(401, 'Authentication required.');
        }

        $scope = $request->queryString('scope', 'all') ?? 'all';
        $userId = (!$user->isAdmin() || $scope === 'me') ? $user->id : null;
        $page = max(1, (int) ($request->queryString('page', '1')));

        $paginator = $this->transactions->paginate($page, 20, $userId);

        return Response::json([
            'data' => array_map($this->toArray(...), $paginator->items),
            'meta' => [
                'page' => $paginator->page,
                'per_page' => $paginator->perPage,
                'total' => $paginator->total,
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'user_id' => $transaction->userId,
            'buyer_name' => $transaction->buyerName,
            'product_name' => $transaction->productName,
            'unit_price' => $transaction->unitPrice,
            'quantity' => $transaction->quantity,
            'total_price' => $transaction->totalPrice,
            'created_at' => $transaction->createdAt,
        ];
    }
}
