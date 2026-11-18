<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\PdoProductRepository;
use App\Repositories\PdoTransactionRepository;
use App\Repositories\PdoUserRepository;

final class TransactionRepositoryTest extends IntegrationTestCase
{
    private PdoTransactionRepository $repo;
    private User $user;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = new PdoTransactionRepository($this->db);

        $this->user = (new PdoUserRepository($this->db))->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@test',
            'password_hash' => 'hash',
            'role' => User::ROLE_USER,
            'is_active' => 1,
        ]);

        $this->product = (new PdoProductRepository($this->db))->create([
            'name' => 'Pepsi', 'slug' => 'pepsi', 'description' => 'Cola',
            'price' => '6.885', 'quantity_available' => 8, 'is_active' => 1,
        ]);
    }

    public function test_creates_a_transaction_with_snapshot_values(): void
    {
        $transaction = $this->createTransaction();

        self::assertSame($this->user->id, $transaction->userId);
        self::assertSame('6.885', $transaction->unitPrice);
        self::assertSame(3, $transaction->quantity);
        self::assertSame('20.655', $transaction->totalPrice);
        self::assertSame('Ada Lovelace', $transaction->buyerName);
    }

    public function test_paginates_newest_first_for_all_or_one_user(): void
    {
        $this->createTransaction();
        $this->createTransaction(quantity: 1);

        $otherUser = (new PdoUserRepository($this->db))->create([
            'name' => 'Grace Hopper',
            'email' => 'grace@test',
            'password_hash' => 'hash',
            'role' => User::ROLE_USER,
            'is_active' => 1,
        ]);

        $third = $this->repo->create([
            'user_id' => $otherUser->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'unit_price' => $this->product->price,
            'quantity' => 1,
            'total_price' => '6.885',
        ]);

        $page = $this->repo->paginate(1, 20);

        self::assertSame(3, $page->total);
        self::assertSame($third->id, $page->items[0]->id);
        self::assertSame(2, $this->repo->paginate(1, 20, $this->user->id)->total);
    }

    public function test_find_by_id_returns_null_for_missing(): void
    {
        self::assertNull($this->repo->findById(999999));
    }

    public function test_soft_deleting_a_product_preserves_its_transaction_history(): void
    {
        $transaction = $this->createTransaction();

        (new PdoProductRepository($this->db))->softDelete($this->product->id);

        $found = $this->repo->findById($transaction->id);

        self::assertNotNull($found);
        self::assertSame('Pepsi', $found->productName);
        self::assertSame('20.655', $found->totalPrice);
    }

    private function createTransaction(int $quantity = 3): Transaction
    {
        return $this->repo->create([
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'unit_price' => $this->product->price,
            'quantity' => $quantity,
            'total_price' => $quantity === 3 ? '20.655' : '6.885',
        ]);
    }
}
