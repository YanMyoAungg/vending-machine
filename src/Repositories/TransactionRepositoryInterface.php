<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\PaginatedResult;
use App\Models\Transaction;

interface TransactionRepositoryInterface
{
    /**
     * @param array{user_id: int, product_id: int, product_name: string, unit_price: string, quantity: int, total_price: string} $data
     */
    public function create(array $data): Transaction;

    public function findById(int $id): ?Transaction;

    /** @return PaginatedResult<Transaction> */
    public function paginate(int $page, int $perPage, ?int $userId = null): PaginatedResult;
}
