<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\ArraySession;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Router;
use App\Core\View;
use App\Database\Connection;
use App\Database\PdoTransactionManager;
use App\Http\Controllers\ProductsController;
use App\Models\PaginatedResult;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\PdoProductRepository;
use App\Repositories\PdoTransactionRepository;
use App\Repositories\PdoUserRepository;
use App\Repositories\TransactionRepositoryInterface;
use PDOException;
use RuntimeException;

final class PurchaseFlowTest extends IntegrationTestCase
{
    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = (new PdoUserRepository($this->db))->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@test',
            'password_hash' => 'hash',
            'role' => User::ROLE_USER,
            'is_active' => 1,
        ]);

        $this->product = (new PdoProductRepository($this->db))->create([
            'name' => 'Pepsi',
            'slug' => 'pepsi',
            'description' => 'Cola',
            'price' => '6.885',
            'quantity_available' => 2,
            'is_active' => 1,
        ]);
    }

    public function test_the_last_units_cannot_be_sold_twice(): void
    {
        $controller = $this->controller();

        $first = $controller->purchase('pepsi', $this->purchaseRequest(2));

        self::assertSame(302, $first->status());

        $second = $controller->purchase('pepsi', $this->purchaseRequest(2));

        self::assertSame(422, $second->status());
        self::assertStringContainsString('Only 0 left', $second->body());

        $pdo = $this->db->pdo();
        self::assertSame(
            0,
            (int) $pdo->query("SELECT quantity_available FROM products WHERE slug = 'pepsi'")->fetchColumn()
        );
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn());
    }

    public function test_a_failed_transaction_rolls_back_the_stock_decrement(): void
    {
        $this->product = (new PdoProductRepository($this->db))->create([
            'name' => 'Water',
            'slug' => 'water',
            'description' => 'Water',
            'price' => '0.500',
            'quantity_available' => 3,
            'is_active' => 1,
        ]);

        $failingTransactions = new class implements TransactionRepositoryInterface {
            public function create(array $data): Transaction
            {
                throw new RuntimeException('write failed');
            }

            public function findById(int $id): ?Transaction
            {
                return null;
            }

            public function paginate(int $page, int $perPage, ?int $userId = null): PaginatedResult
            {
                return new PaginatedResult([], 0, $page, $perPage);
            }
        };

        try {
            $this->controller($failingTransactions)->purchase('water', $this->purchaseRequest(2));
            self::fail('Expected the write failure to bubble up');
        } catch (RuntimeException $exception) {
            self::assertSame('write failed', $exception->getMessage());
        }

        $pdo = $this->db->pdo();
        self::assertSame(
            3,
            (int) $pdo->query("SELECT quantity_available FROM products WHERE slug = 'water'")->fetchColumn()
        );
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn());
    }

    public function test_find_by_slug_for_update_blocks_a_second_connection(): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            (new PdoProductRepository($this->db))->findBySlugForUpdate('pepsi');

            $other = Connection::fromEnv();
            $other->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');

            $this->expectException(PDOException::class);

            (new PdoProductRepository($other))->findBySlugForUpdate('pepsi');
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    private function controller(?TransactionRepositoryInterface $transactions = null): ProductsController
    {
        $products = new PdoProductRepository($this->db);
        $transactions ??= new PdoTransactionRepository($this->db);
        $session = new ArraySession();
        $router = new Router(new Container());
        $router->addController(ProductsController::class);
        $view = new View(dirname(__DIR__, 2) . '/src/Views', $router, $session, new Csrf($session));

        return new ProductsController(
            $products,
            $transactions,
            new PdoTransactionManager($this->db),
            $view,
            $router,
            $session,
            new \App\Core\Validator()
        );
    }

    private function purchaseRequest(int $quantity): Request
    {
        return (new Request('POST', '/products/pepsi/purchase', body: ['quantity' => (string) $quantity]))
            ->withAttribute('user', $this->user);
    }
}
